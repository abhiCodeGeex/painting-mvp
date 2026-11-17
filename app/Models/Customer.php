<?php

namespace App\Models;

use App\Enums\AddressKind;
use App\Enums\CustomerType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Customer profile attached to a "customer" user account (spec §5).
 */
class Customer extends Model
{
    use LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'tax_exempt' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * @return BelongsTo<EnquirySource, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(EnquirySource::class, 'source_id');
    }

    /**
     * @return HasMany<Address, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    /**
     * @return HasOne<Address, $this>
     */
    public function billingAddress(): HasOne
    {
        return $this->hasOne(Address::class)->where('kind', AddressKind::Billing)->latestOfMany();
    }

    /**
     * @return HasMany<Enquiry, $this>
     */
    public function enquiries(): HasMany
    {
        return $this->hasMany(Enquiry::class);
    }

    /** Company name for companies, otherwise the person's name. */
    public function displayName(): string
    {
        return $this->type === CustomerType::Company && filled($this->company_name)
            ? $this->company_name
            : (string) $this->user?->name;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->logExcept(['updated_at', 'created_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
