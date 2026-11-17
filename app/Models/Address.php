<?php

namespace App\Models;

use App\Enums\AddressKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => AddressKind::class,
            'is_default' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // One default address per kind per customer.
        static::saved(function (Address $address): void {
            if ($address->is_default) {
                static::where('customer_id', $address->customer_id)
                    ->where('kind', $address->kind)
                    ->whereKeyNot($address->getKey())
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<State, $this>
     */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** Single-line address, e.g. "12 MG Road, Bengaluru 560001, Karnataka". */
    public function oneLine(): string
    {
        return collect([
            $this->line1,
            $this->line2,
            trim($this->city.' '.$this->pin_code),
            $this->state?->name,
        ])->filter()->implode(', ');
    }
}
