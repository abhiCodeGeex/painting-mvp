<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * An order placed from an approved quotation, paid in two parts (token, then full & final on delivery).
 * Changes go through App\Domain\Orders\OrderService.
 */
class Order extends Model
{
    use LogsActivity;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'grand_total' => 'integer',
            'token_amount' => 'integer',
            'placed_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Enquiry, $this> */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed();
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function placer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'placed_by');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderByRaw("case kind when 'token' then 0 else 1 end")->chaperone();
    }

    /** @return HasOne<Payment, $this> */
    public function tokenPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->where('kind', PaymentKind::Token->value)->chaperone();
    }

    /** @return HasOne<Payment, $this> */
    public function finalPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->where('kind', PaymentKind::Final->value)->chaperone();
    }

    public function amountPaid(): int
    {
        return (int) $this->payments->where('status', PaymentStatus::Received)->sum('amount');
    }

    public function balanceDue(): int
    {
        return max(0, $this->grand_total - $this->amountPaid());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'token_amount', 'delivered_at'])->logOnlyDirty()->dontLogEmptyChanges();
    }
}
