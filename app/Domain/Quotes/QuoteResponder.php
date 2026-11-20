<?php

namespace App\Domain\Quotes;

use App\Domain\Enquiries\EnquiryWorkflow;
use App\Enums\EnquiryStatus;
use App\Enums\QuoteStatus;
use App\Models\Quote;
use App\Models\RejectionReason;
use App\Models\User;
use App\Notifications\QuoteResponded;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The customer's answer to a quotation (spec §9): approve, request changes, reject, or ask for a
 * re-quote once it has expired. Every answer is recorded on the quote and in the enquiry thread,
 * and sales is notified so the negotiation can continue with a new version.
 */
class QuoteResponder
{
    public function __construct(
        private readonly EnquiryWorkflow $workflow,
        private readonly QuoteBuilder $builder,
    ) {}

    /**
     * @param  array{name: string, role?: ?string, po_number?: ?string, comment?: ?string, optional_line_ids?: list<int>}  $data
     */
    public function approve(Quote $quote, User $by, array $data, Request $request): Quote
    {
        $this->ensureCanRespond($quote);

        DB::transaction(function () use ($quote, $by, $data, $request): void {
            // Optional lines the customer kept; everything else optional is dropped from the total.
            $keep = array_map('intval', $data['optional_line_ids'] ?? []);
            foreach ($quote->lines as $line) {
                if ($line->is_optional) {
                    $line->is_selected = in_array($line->id, $keep, true);
                    $line->saveQuietly();
                }
            }
            $this->builder->recalculate($quote);

            $quote->fill([
                'status' => QuoteStatus::Approved,
                'responded_at' => now(),
                'responded_by' => $by->id,
                'response' => [
                    'action' => 'approved',
                    'name' => $data['name'],
                    'role' => $data['role'] ?? null,
                    'email' => $by->email,
                    'po_number' => $data['po_number'] ?? null,
                    'comment' => $data['comment'] ?? null,
                    'optional_line_ids' => $keep,
                    'grand_total' => $quote->grand_total,
                    'ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                ],
            ])->save();

            // Re-render the stored PDF with the "Approved on … by …" stamp.
            $quote->addMediaFromString($this->builder->renderPdf($quote))
                ->usingFileName(str($quote->displayNumber())->slug().'-approved.pdf')
                ->usingName($quote->displayNumber())
                ->toMediaCollection('pdf');

            $this->workflow->recordQuoteResponse(
                $quote->enquiry, $by, EnquiryStatus::Approved,
                "Quotation {$quote->displayNumber()} approved by {$data['name']} (".Money::format($quote->grand_total).').',
                $data['comment'] ?? null,
            );
        });

        $this->notify($quote, 'approved', confirmCustomer: true);

        return $quote;
    }

    public function requestChanges(Quote $quote, User $by, string $comment, Request $request): Quote
    {
        $this->ensureCanRespond($quote);

        DB::transaction(function () use ($quote, $by, $comment, $request): void {
            $quote->fill([
                'status' => QuoteStatus::ChangesRequested,
                'responded_at' => now(),
                'responded_by' => $by->id,
                'response' => ['action' => 'changes_requested', 'comment' => $comment, 'ip' => $request->ip()],
            ])->save();

            $this->workflow->recordQuoteResponse(
                $quote->enquiry, $by, EnquiryStatus::ChangesRequested,
                "Changes requested on quotation {$quote->displayNumber()}.",
                $comment,
            );
        });

        $this->notify($quote, 'changes_requested');

        return $quote;
    }

    public function reject(Quote $quote, User $by, RejectionReason $reason, ?string $comment, Request $request): Quote
    {
        $this->ensureCanRespond($quote);

        if ($reason->requires_comment && blank($comment)) {
            throw ValidationException::withMessages(['comment' => 'Please tell us a little more about this reason.']);
        }

        DB::transaction(function () use ($quote, $by, $reason, $comment, $request): void {
            $quote->fill([
                'status' => QuoteStatus::Rejected,
                'responded_at' => now(),
                'responded_by' => $by->id,
                'rejection_reason_id' => $reason->id,
                'response' => ['action' => 'rejected', 'reason' => $reason->name, 'comment' => $comment, 'ip' => $request->ip()],
            ])->save();

            $this->workflow->recordQuoteResponse(
                $quote->enquiry, $by, EnquiryStatus::Rejected,
                "Quotation {$quote->displayNumber()} declined: {$reason->name}.",
                $comment,
            );
        });

        $this->notify($quote, 'rejected');

        return $quote;
    }

    /**
     * An expired quote cannot be approved; the customer asks for a fresh one instead (spec §9.4).
     */
    public function requestRequote(Quote $quote, User $by, ?string $comment): Quote
    {
        $enquiry = $quote->enquiry;
        if (! $this->isLatest($quote) || ! $quote->isPastValidity() || ! in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Viewed], true)) {
            throw ValidationException::withMessages(['status' => 'A re-quote can only be requested for an expired quotation.']);
        }

        DB::transaction(function () use ($quote, $by, $comment, $enquiry): void {
            $quote->update(['status' => QuoteStatus::Expired]);
            $this->workflow->recordQuoteResponse(
                $enquiry, $by, EnquiryStatus::UnderReview,
                "Re-quote requested for expired quotation {$quote->displayNumber()}.",
                $comment,
            );
        });

        $this->notify($quote, 'requote');

        return $quote;
    }

    private function ensureCanRespond(Quote $quote): void
    {
        if (! $this->isLatest($quote)) {
            throw ValidationException::withMessages(['status' => 'A newer version of this quotation exists. Please respond to the latest one.']);
        }
        if ($quote->isPastValidity() && in_array($quote->status, [QuoteStatus::Sent, QuoteStatus::Viewed], true)) {
            throw ValidationException::withMessages(['status' => 'This quotation has expired. Ask us for a re-quote.']);
        }
        if (! $quote->awaitsResponse()) {
            throw ValidationException::withMessages(['status' => 'This quotation has already been answered.']);
        }
    }

    private function isLatest(Quote $quote): bool
    {
        return (int) $quote->enquiry->quotes()->whereNotNull('sent_at')->max('version') === (int) $quote->version;
    }

    private function notify(Quote $quote, string $action, bool $confirmCustomer = false): void
    {
        $quote->refresh();
        Notification::send($this->workflow->staffRecipients($quote->enquiry), new QuoteResponded($quote, $action));

        if ($confirmCustomer) {
            $quote->enquiry->customer->user?->notify(new QuoteResponded($quote, $action));
        }
    }
}
