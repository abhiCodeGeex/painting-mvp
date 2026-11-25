<?php

namespace App\Models\Concerns;

use App\Enums\StockUnit;
use App\Models\StockItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * A catalogue item we physically hold in stock. Every such item gets one StockItem row
 * (created with it, quantity 0) whose quantity only changes through StockItem::adjust().
 *
 * @mixin Model
 */
trait HasStock
{
    public static function bootHasStock(): void
    {
        static::created(fn ($model) => $model->syncStockItem());
        static::updated(fn ($model) => $model->syncStockItem());
    }

    /**
     * @return MorphOne<StockItem, $this>
     */
    public function stockItem(): MorphOne
    {
        return $this->morphOne(StockItem::class, 'stockable');
    }

    abstract public function stockUnit(): StockUnit;

    /**
     * Whether this particular item is a physical stock item (e.g. "No glazing" is not).
     */
    public function tracksStock(): bool
    {
        return true;
    }

    public function syncStockItem(): void
    {
        $item = $this->stockItem()->first();

        if (! $this->tracksStock()) {
            // e.g. a glazing changed to "No glazing": drop its stock row unless it holds stock or history.
            if ($item !== null && $item->quantity <= 0 && ! $item->movements()->exists()) {
                $item->delete();
            }
            $this->unsetRelation('stockItem');

            return;
        }

        $unit = $this->stockUnit();

        if ($item === null) {
            $this->stockItem()->create(['unit' => $unit]);
        } elseif ($item->unit !== $unit) {
            $item->updateQuietly(['unit' => $unit]);
        }

        $this->unsetRelation('stockItem');
    }
}
