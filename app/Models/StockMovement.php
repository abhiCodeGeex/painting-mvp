<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change to an item's stock: + received / − used, with who did it and why.
 */
class StockMovement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'change' => 'float',
            'quantity_after' => 'float',
        ];
    }

    /**
     * @return BelongsTo<StockItem, $this>
     */
    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
