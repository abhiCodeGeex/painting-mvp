<?php

namespace App\Models;

use App\Enums\BudgetRange;
use App\Enums\EnquiryStatus;
use App\Enums\PreferredContact;
use App\Enums\Priority;
use App\Enums\ProjectType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A customer's request for one or more artworks (spec §6). Status changes go through
 * App\Domain\Enquiries\EnquiryWorkflow — never set "status" directly.
 */
class Enquiry extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'draft',
        'priority' => 'normal',
        'project_type' => 'home',
        'preferred_contact' => 'email',
    ];

    protected function casts(): array
    {
        return [
            'status' => EnquiryStatus::class,
            'project_type' => ProjectType::class,
            'budget_range' => BudgetRange::class,
            'preferred_contact' => PreferredContact::class,
            'priority' => Priority::class,
            'required_by' => 'date',
            'installation_needed' => 'boolean',
            'submitted_at' => 'datetime',
            'last_customer_activity_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'estimate_snapshot' => 'array',
        ];
    }

    /**
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class)->orderByDesc('version');
    }

    /** The newest quote version that has been sent to the customer. */
    public function latestSentQuote(): ?Quote
    {
        return $this->quotes()->whereNotNull('sent_at')->first();
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /**
     * @return BelongsTo<EnquirySource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(EnquirySource::class, 'source_id')->withTrashed();
    }

    /**
     * @return BelongsTo<Address, $this>
     */
    public function deliveryAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'delivery_address_id')->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return HasMany<EnquiryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(EnquiryLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return MorphMany<StatusHistory, $this>
     */
    public function statusHistory(): MorphMany
    {
        return $this->morphMany(StatusHistory::class, 'subject')->orderBy('created_at')->orderBy('id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')->useDisk('local');
    }

    /** Reference once submitted, otherwise "Draft #id". */
    public function displayReference(): string
    {
        return $this->reference ?? 'Draft #'.$this->id;
    }

    public function isEditableByCustomer(): bool
    {
        return $this->status === EnquiryStatus::Draft;
    }

    /**
     * Staff can open the enquiry editor on drafts (raised for a customer) and on enquiries
     * still waiting for a quote, to adjust artworks on the customer's behalf.
     */
    public function isEditableByStaff(): bool
    {
        return in_array($this->status, [EnquiryStatus::Draft, EnquiryStatus::Submitted, EnquiryStatus::UnderReview, EnquiryStatus::NeedsClarification], true);
    }

    /**
     * Whether this user may change the enquiry in the editor now. The status is checked here as
     * well as in the policy, because Shield lets admins pass every policy check.
     */
    public function isEditableBy(User $user): bool
    {
        return $user->can('edit', $this)
            && ($user->isStaff() ? $this->isEditableByStaff() : $this->isEditableByCustomer());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'assigned_to', 'priority', 'project_name', 'internal_notes', 'required_by'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
