<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Enquiry lifecycle (spec §3.1). Quote-related statuses are set from M4 onwards.
 */
enum EnquiryStatus: string implements HasColor, HasLabel
{
    use HasOptions;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case NeedsClarification = 'needs_clarification';
    case Quoted = 'quoted';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::NeedsClarification => 'Needs clarification',
            self::Quoted => 'Quoted',
            self::ChangesRequested => 'Changes requested',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Wording shown to customers in the portal. */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Submitted, self::UnderReview => 'We are reviewing it',
            self::NeedsClarification => 'Waiting for your reply',
            default => $this->getLabel(),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted => 'info',
            self::UnderReview => 'warning',
            self::NeedsClarification => 'danger',
            self::Quoted, self::ChangesRequested => 'primary',
            self::Approved => 'success',
            self::Rejected, self::Expired, self::Cancelled => 'gray',
        };
    }

    /** Statuses in which the customer can still cancel. */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Draft, self::Submitted, self::UnderReview, self::NeedsClarification], true);
    }

    /** Statuses in which the customer may add messages. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Draft, self::Cancelled, self::Approved, self::Rejected, self::Expired], true);
    }
}
