<?php

namespace App\Domain\Enquiries;

use App\Domain\Pricing\CustomerEstimate;
use App\Domain\Support\DocumentNumber;
use App\Enums\EnquiryStatus;
use App\Enums\MessageKind;
use App\Enums\Role;
use App\Models\Enquiry;
use App\Models\Message;
use App\Models\StatusHistory;
use App\Models\User;
use App\Notifications\EnquiryCancelled;
use App\Notifications\EnquirySubmitted;
use App\Notifications\EnquirySubmittedForStaff;
use App\Notifications\NewEnquiryMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Every enquiry status change happens here (spec §3.3): it validates the transition,
 * records status history, posts a system message and notifies the other side.
 */
class EnquiryWorkflow
{
    /**
     * Customer (or staff on their behalf) submits a draft.
     */
    public function submit(Enquiry $enquiry, User $by): Enquiry
    {
        $this->ensureStatus($enquiry, [EnquiryStatus::Draft], 'Only drafts can be submitted.');

        $enquiry->loadCount('lines');
        $errors = [];
        if ($enquiry->lines_count === 0) {
            $errors['lines'] = 'Add at least one artwork before submitting.';
        }
        if (! $enquiry->delivery_address_id) {
            $errors['delivery_address_id'] = 'Choose a delivery address.';
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($enquiry, $by): void {
            $enquiry->reference ??= DocumentNumber::next('ENQ');
            $enquiry->submitted_at = now();
            $enquiry->last_customer_activity_at = now();
            // What the customer was shown, so staff can compare it with the quote they prepare.
            $enquiry->estimate_snapshot = app(CustomerEstimate::class)->forEnquiry($enquiry);
            $this->transition($enquiry, EnquiryStatus::Submitted, $by);
            $this->systemMessage($enquiry, 'Enquiry submitted.');
        });

        $enquiry->customer->user?->notify(new EnquirySubmitted($enquiry));
        Notification::send($this->staffRecipients($enquiry), new EnquirySubmittedForStaff($enquiry));

        return $enquiry;
    }

    /**
     * Staff picks the enquiry up. Assigns it to them unless already assigned.
     */
    public function startReview(Enquiry $enquiry, User $by): Enquiry
    {
        $this->ensureStatus($enquiry, [EnquiryStatus::Submitted], 'Only submitted enquiries can be taken into review.');

        DB::transaction(function () use ($enquiry, $by): void {
            $enquiry->assigned_to ??= $by->id;
            $this->transition($enquiry, EnquiryStatus::UnderReview, $by);
        });

        return $enquiry;
    }

    public function assign(Enquiry $enquiry, ?User $assignee, User $by): Enquiry
    {
        $enquiry->assigned_to = $assignee?->id;
        $enquiry->save();

        $this->systemMessage($enquiry, $assignee
            ? "Assigned to {$assignee->name}."
            : 'Unassigned.', internalOnly: true, by: $by);

        return $enquiry;
    }

    /**
     * Staff asks the customer something: enquiry waits for the customer's reply.
     *
     * @param  array<int, UploadedFile|string>  $attachments
     */
    public function askQuestion(Enquiry $enquiry, User $staff, string $body, array $attachments = []): Message
    {
        $this->ensureStatus(
            $enquiry,
            [EnquiryStatus::Submitted, EnquiryStatus::UnderReview, EnquiryStatus::NeedsClarification],
            'Questions can only be asked while the enquiry is being reviewed.',
        );

        $message = DB::transaction(function () use ($enquiry, $staff, $body, $attachments): Message {
            $enquiry->assigned_to ??= $staff->id;
            $this->transition($enquiry, EnquiryStatus::NeedsClarification, $staff, 'Question sent to customer');

            return $this->createMessage($enquiry, $staff, $body, MessageKind::Question, $attachments);
        });

        $enquiry->customer->user?->notify(new NewEnquiryMessage($message));

        return $message;
    }

    /**
     * A normal message from either side. A customer reply to a question moves the enquiry
     * back to "Under review".
     *
     * @param  array<int, UploadedFile|string>  $attachments
     */
    public function postMessage(Enquiry $enquiry, User $author, string $body, array $attachments = []): Message
    {
        if (! $enquiry->status->isOpen()) {
            throw ValidationException::withMessages(['body' => 'This enquiry is closed for messages.']);
        }

        $message = DB::transaction(function () use ($enquiry, $author, $body, $attachments): Message {
            $message = $this->createMessage($enquiry, $author, $body, MessageKind::Message, $attachments);

            if ($author->isCustomer()) {
                $enquiry->last_customer_activity_at = now();
                if ($enquiry->status === EnquiryStatus::NeedsClarification) {
                    $this->transition($enquiry, EnquiryStatus::UnderReview, $author, 'Customer replied');
                } else {
                    $enquiry->save();
                }
            }

            return $message;
        });

        if ($author->isCustomer()) {
            Notification::send($this->staffRecipients($enquiry), new NewEnquiryMessage($message));
        } else {
            $enquiry->customer->user?->notify(new NewEnquiryMessage($message));
        }

        return $message;
    }

    public function cancel(Enquiry $enquiry, User $by, ?string $reason = null): Enquiry
    {
        if (! $enquiry->status->isCancellable()) {
            throw ValidationException::withMessages(['status' => 'This enquiry can no longer be cancelled.']);
        }

        $wasDraft = $enquiry->status === EnquiryStatus::Draft;

        DB::transaction(function () use ($enquiry, $by, $reason): void {
            $enquiry->cancelled_at = now();
            $enquiry->cancelled_reason = $reason;
            $this->transition($enquiry, EnquiryStatus::Cancelled, $by, $reason);
            $this->systemMessage($enquiry, 'Enquiry cancelled'.($reason ? ": {$reason}" : '.'), by: $by);
        });

        if (! $wasDraft && $by->isCustomer()) {
            Notification::send($this->staffRecipients($enquiry), new EnquiryCancelled($enquiry));
        }

        return $enquiry;
    }

    /**
     * Messages from the other side are marked read when the thread is opened.
     */
    public function markThreadRead(Enquiry $enquiry, User $reader): void
    {
        $enquiry->messages()
            ->whereNull('read_at')
            ->where('from_staff', ! $reader->isStaff())
            ->where('kind', '!=', MessageKind::System)
            ->update(['read_at' => now()]);
    }

    /**
     * A quotation has been sent (called by QuoteBuilder::send).
     */
    public function markQuoted(Enquiry $enquiry, User $by, string $note): void
    {
        if ($enquiry->status !== EnquiryStatus::Quoted) {
            $this->transition($enquiry, EnquiryStatus::Quoted, $by, $note);
        }
        $this->systemMessage($enquiry, $note, by: $by);
    }

    /**
     * Assignee if there is one, otherwise every active admin.
     *
     * @return Collection<int, User>
     */
    public function staffRecipients(Enquiry $enquiry): Collection
    {
        if ($enquiry->assignee && $enquiry->assignee->is_active) {
            return collect([$enquiry->assignee]);
        }

        return User::staff()->where('is_active', true)->role(Role::SuperAdmin->value)->get();
    }

    private function transition(Enquiry $enquiry, EnquiryStatus $to, User $by, ?string $note = null): void
    {
        $from = $enquiry->status;
        $enquiry->status = $to;
        $enquiry->save();

        StatusHistory::create([
            'subject_type' => $enquiry->getMorphClass(),
            'subject_id' => $enquiry->getKey(),
            'from' => $from?->value,
            'to' => $to->value,
            'user_id' => $by->id,
            'note' => $note,
        ]);
    }

    /**
     * @param  array<int, UploadedFile|string>  $attachments  uploaded files or absolute paths
     */
    private function createMessage(Enquiry $enquiry, User $author, string $body, MessageKind $kind, array $attachments): Message
    {
        $message = $enquiry->messages()->create([
            'user_id' => $author->id,
            'kind' => $kind,
            'body' => trim($body),
            'from_staff' => $author->isStaff(),
        ]);

        // Uploaded files or absolute paths of temporary uploads (moved into the media library).
        foreach ($attachments as $file) {
            $message->addMedia($file)->toMediaCollection('attachments');
        }

        return $message;
    }

    private function systemMessage(Enquiry $enquiry, string $body, bool $internalOnly = false, ?User $by = null): void
    {
        if ($internalOnly) {
            return; // Internal events live in the activity log only.
        }

        $enquiry->messages()->create([
            'user_id' => $by?->id,
            'kind' => MessageKind::System,
            'body' => $body,
            'from_staff' => true,
            'read_at' => now(),
        ]);
    }

    /**
     * @param  list<EnquiryStatus>  $allowed
     */
    private function ensureStatus(Enquiry $enquiry, array $allowed, string $message): void
    {
        if (! in_array($enquiry->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }
}
