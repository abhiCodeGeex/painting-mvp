<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One version of a quotation for an enquiry (spec §8). Totals are kept up to date by
 * App\Domain\Quotes\QuoteBuilder::recalculate(); sending goes through QuoteBuilder::send().
 */
class Quote extends Model implements HasMedia
{
    use InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft', 'discount_percent' => 0, 'discount_amount' => 0];

    protected static function booted(): void
    {
        // Empty money boxes in the admin form arrive as null.
        static::saving(function (Quote $quote): void {
            $quote->discount_amount ??= 0;
            $quote->discount_percent ??= 0;
        });
    }

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'issue_date' => 'date',
            'valid_until' => 'date',
            'intra_state' => 'boolean',
            'customer_snapshot' => 'array',
            'delivery_snapshot' => 'array',
            'sent_via' => 'array',
            'sent_at' => 'datetime',
            'viewed_at' => 'datetime',
            'discount_percent' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'items_total' => 'integer',
            'extras_total' => 'integer',
            'discount_amount' => 'integer',
            'taxable' => 'integer',
            'cgst' => 'integer',
            'sgst' => 'integer',
            'igst' => 'integer',
            'tax_total' => 'integer',
            'grand_total' => 'integer',
            'total_cost' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Enquiry, $this>
     */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /**
     * @return HasMany<QuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position');
    }

    /**
     * @return HasMany<QuoteExtra, $this>
     */
    public function extras(): HasMany
    {
        return $this->hasMany(QuoteExtra::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('pdf')->useDisk('local')->singleFile();
    }

    public function displayNumber(): string
    {
        return "{$this->number} v{$this->version}";
    }

    public function marginPercent(): ?float
    {
        return $this->taxable > 0 ? round(($this->taxable - $this->total_cost) / $this->taxable * 100, 1) : null;
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'grand_total', 'valid_until', 'sent_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
