<?php

namespace App\Models;

use App\Enums\StockStatus;
use App\Enums\StockUnit;
use App\Notifications\LowStockAlert;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Stock on hand for one catalogue item (frame moulding, mat, glazing or substrate).
 *
 * Status: Out at 0, Low under the threshold (20 by default), otherwise OK. When the status
 * gets worse (OK → Low, OK/Low → Out) admins get one alert; further drops within the same
 * status do not alert again.
 */
class StockItem extends Model
{
    public const DEFAULT_THRESHOLD = 20;

    /**
     * Item types that are stocked, with the label shown in the inventory.
     */
    public const TYPES = [
        Frame::class => 'Frame moulding',
        Mat::class => 'Mat board',
        Glazing::class => 'Glazing',
        Substrate::class => 'Substrate',
    ];

    protected $guarded = ['id'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'quantity' => 0,
        'low_stock_threshold' => self::DEFAULT_THRESHOLD,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'low_stock_threshold' => 'float',
            'unit' => StockUnit::class,
        ];
    }

    protected static function booted(): void
    {
        static::updated(function (StockItem $item): void {
            if (! $item->wasChanged(['quantity', 'low_stock_threshold'])) {
                return;
            }

            $before = StockStatus::for((float) $item->getOriginal('quantity'), (float) $item->getOriginal('low_stock_threshold'));
            $after = $item->status();

            if ($after->severity() > $before->severity()) {
                Notification::send(LowStockAlert::recipients(), new LowStockAlert($item, $after));
            }
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function stockable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('id');
    }

    public function status(): StockStatus
    {
        return StockStatus::for($this->quantity, $this->low_stock_threshold);
    }

    /**
     * Add (positive) or remove (negative) stock and log the movement. Stock never goes below 0.
     */
    public function adjust(float $change, ?string $note = null, ?User $by = null): StockMovement
    {
        return $this->move(fn (float $current): float => $current + $change, $note, $by);
    }

    /**
     * Set the stock to a counted quantity (e.g. after a stock take). The change is worked out
     * against the stock in the database at that moment, not a copy that may be out of date.
     */
    public function countAs(float $quantity, ?string $note = null, ?User $by = null): StockMovement
    {
        return $this->move(fn (): float => $quantity, $note ?: 'Stock count', $by);
    }

    /**
     * Locks the row, applies the new quantity and logs the movement in one transaction.
     *
     * @param  callable(float): float  $newQuantity
     */
    private function move(callable $newQuantity, ?string $note, ?User $by): StockMovement
    {
        return DB::transaction(function () use ($newQuantity, $note, $by): StockMovement {
            $locked = static::query()->lockForUpdate()->findOrFail($this->getKey());
            $before = $locked->quantity;
            $after = round($newQuantity($before), 2);

            if ($after < 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'Only '.self::formatQuantity($before, $locked->unit).' in stock — cannot remove '.self::formatQuantity(round($before - $after, 2), $locked->unit).'.',
                ]);
            }

            // Saving the locked copy keeps the "was it OK before?" check (booted) on true values.
            $locked->quantity = $after;
            $locked->save();
            $this->setRawAttributes($locked->getAttributes(), true);

            return $locked->movements()->create([
                'change' => round($after - $before, 2),
                'quantity_after' => $after,
                'note' => $note,
                'user_id' => $by?->getKey(),
            ]);
        });
    }

    /**
     * Only items that are still in the catalogue (not archived).
     *
     * @param  Builder<StockItem>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereHasMorph('stockable', array_keys(self::TYPES));
    }

    /**
     * @param  Builder<StockItem>  $query
     */
    public function scopeWithStatus(Builder $query, StockStatus $status): void
    {
        match ($status) {
            StockStatus::Out => $query->where('quantity', '<=', 0),
            StockStatus::Low => $query->where('quantity', '>', 0)->whereColumn('quantity', '<', 'low_stock_threshold'),
            StockStatus::Ok => $query->where('quantity', '>', 0)->whereColumn('quantity', '>=', 'low_stock_threshold'),
        };
    }

    /**
     * Low or out of stock.
     *
     * @param  Builder<StockItem>  $query
     */
    public function scopeNeedsAttention(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('quantity', '<=', 0)->orWhereColumn('quantity', '<', 'low_stock_threshold'));
    }

    public function name(): string
    {
        $item = $this->stockable;

        return (string) ($item?->name ?? 'Deleted item');
    }

    public function code(): ?string
    {
        return $this->stockable?->code ?? null;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->stockable_type] ?? class_basename($this->stockable_type);
    }

    public function formattedQuantity(): string
    {
        return self::formatQuantity($this->quantity, $this->unit);
    }

    public static function formatQuantity(float $quantity, ?StockUnit $unit): string
    {
        $number = rtrim(rtrim(number_format($quantity, 2, '.', ','), '0'), '.');

        return trim($number.' '.($unit?->getLabel() ?? ''));
    }
}
