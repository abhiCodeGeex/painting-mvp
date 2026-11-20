<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Quotes\QuoteBuilder;
use App\Domain\Quotes\QuoteResponder;
use App\Enums\CustomerType;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Models\Quote;
use App\Models\RejectionReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quote PDFs and the customer's response (approve / request changes / reject / re-quote).
 * Customers get the stored PDF of a sent quote; staff can also preview drafts.
 */
class QuoteController extends Controller
{
    public function pdf(Request $request, Enquiry $enquiry, Quote $quote): Response
    {
        Gate::authorize('view', $enquiry);
        abort_unless($quote->enquiry_id === $enquiry->id && $quote->sent_at, 404);

        if ($quote->status === QuoteStatus::Sent) {
            $quote->update(['status' => QuoteStatus::Viewed, 'viewed_at' => now()]);
        }

        return $this->stored($quote, $request->boolean('download'));
    }

    /** Customer approves the latest quotation (spec §9.1). */
    public function approve(Request $request, Enquiry $enquiry, Quote $quote, QuoteResponder $responder): RedirectResponse
    {
        $this->authorizeResponse($request, $enquiry, $quote);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'role' => [$enquiry->customer->type === CustomerType::Company ? 'required' : 'nullable', 'string', 'max:120'],
            'po_number' => ['nullable', 'string', 'max:60'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'optional_line_ids' => ['nullable', 'array'],
            'optional_line_ids.*' => ['integer', Rule::exists('quote_lines', 'id')->where('quote_id', $quote->id)],
            'accept_terms' => ['accepted'],
        ], [
            'name.required' => 'Please type your full name to sign the approval.',
            'role.required' => 'Please add your job title or role.',
            'accept_terms.accepted' => 'Please accept the terms & conditions.',
        ]);

        $responder->approve($quote, $request->user(), $data, $request);

        return back()->with('success', 'Thank you! Your approval has been sent.');
    }

    /** Customer asks for changes; sales prepares a new version (spec §9.2). */
    public function requestChanges(Request $request, Enquiry $enquiry, Quote $quote, QuoteResponder $responder): RedirectResponse
    {
        $this->authorizeResponse($request, $enquiry, $quote);

        $data = $request->validate([
            'comment' => ['required', 'string', 'max:5000'],
        ], ['comment.required' => 'Please tell us what you would like to change.']);

        $responder->requestChanges($quote, $request->user(), $data['comment'], $request);

        return back()->with('success', 'Your change request has been sent. We will send you a revised quotation.');
    }

    /** Customer declines the quotation with a reason (spec §9.3). */
    public function reject(Request $request, Enquiry $enquiry, Quote $quote, QuoteResponder $responder): RedirectResponse
    {
        $this->authorizeResponse($request, $enquiry, $quote);

        $data = $request->validate([
            'rejection_reason_id' => ['required', Rule::exists('rejection_reasons', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'comment' => ['nullable', 'string', 'max:5000'],
        ], ['rejection_reason_id.required' => 'Please choose a reason.']);

        $responder->reject($quote, $request->user(), RejectionReason::findOrFail($data['rejection_reason_id']), $data['comment'] ?? null, $request);

        return back()->with('success', 'Thank you for letting us know.');
    }

    /** Expired quotation: ask for a fresh one (spec §9.4). */
    public function requote(Request $request, Enquiry $enquiry, Quote $quote, QuoteResponder $responder): RedirectResponse
    {
        $this->authorizeResponse($request, $enquiry, $quote);

        $data = $request->validate(['comment' => ['nullable', 'string', 'max:5000']]);
        $responder->requestRequote($quote, $request->user(), $data['comment'] ?? null);

        return back()->with('success', 'We will prepare an updated quotation for you.');
    }

    private function authorizeResponse(Request $request, Enquiry $enquiry, Quote $quote): void
    {
        Gate::authorize('view', $enquiry);
        abort_unless($quote->enquiry_id === $enquiry->id && $quote->sent_at, 404);
        abort_unless($request->user()->isCustomer(), 403);
    }

    public function adminPdf(Request $request, Quote $quote, QuoteBuilder $builder): Response
    {
        abort_unless($request->user()?->isStaff(), 403);

        if ($quote->getFirstMedia('pdf')) {
            return $this->stored($quote, false);
        }

        // Draft: render a live preview.
        return response($builder->renderPdf($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str($quote->displayNumber())->slug().'-draft.pdf"',
        ]);
    }

    private function stored(Quote $quote, bool $download): Response
    {
        $media = $quote->getFirstMedia('pdf');
        abort_unless($media && is_file($media->getPath()), 404);

        return response()->file($media->getPath(), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$media->file_name.'"',
        ]);
    }
}
