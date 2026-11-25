<?php

namespace Database\Seeders;

use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\StockItem;
use App\Models\Substrate;
use Illuminate\Database\Seeder;

/**
 * Local demo only: opening stock for the catalogue, with a few items low or out so the
 * dashboard warnings and inventory colours show. Only touches items with no stock history,
 * and does not send low-stock alerts.
 */
class DemoStockSeeder extends Seeder
{
    /**
     * [model, code or name] => [quantity, alert level (null = default 20)]
     */
    private const LEVELS = [
        [Frame::class, 'FR-BLK-20', 120, null],
        [Frame::class, 'FR-WHT-20', 85, null],
        [Frame::class, 'FR-OAK-25', 14.5, null],   // low
        [Frame::class, 'FR-WAL-30', 48, null],
        [Frame::class, 'FR-GLD-40', 0, null],      // out
        [Frame::class, 'FR-ALU-15', 9, null],      // low
        [Frame::class, 'FR-FLT-BLK', 32, null],
        [Mat::class, 'MAT-WHT', 60, null],
        [Mat::class, 'MAT-IVR', 18, null],         // low
        [Mat::class, 'MAT-BLK', 4, null],          // low
        [Mat::class, 'MAT-GRY', 35, null],
        [Glazing::class, 'Float glass 2 mm', 40, null],
        [Glazing::class, 'Clear acrylic 3 mm', 25, null],
        [Glazing::class, 'UV-protective acrylic', 12, null],          // low
        [Glazing::class, 'Museum glass (anti-reflective, UV)', 0, null], // out
        [Substrate::class, 'Canvas roll (unstretched)', 6, 3],          // rolls: alert under 3
        [Substrate::class, 'Fine-art paper 310 gsm', 200, null],
        [Substrate::class, 'Wood panel 18 mm', 15, null],               // low
    ];

    private const DEFAULT_QUANTITY = 50;

    public function run(): void
    {
        $levels = [];
        foreach (self::LEVELS as [$model, $key, $quantity, $threshold]) {
            $levels[$model][$key] = [$quantity, $threshold];
        }

        StockItem::query()->current()->with('stockable')->doesntHave('movements')->each(function (StockItem $item) use ($levels): void {
            $stockable = $item->stockable;
            $key = $stockable->code ?? $stockable->name;
            [$quantity, $threshold] = $levels[$item->stockable_type][$key] ?? [self::DEFAULT_QUANTITY, null];

            $item->forceFill([
                'quantity' => $quantity,
                'low_stock_threshold' => $threshold ?? StockItem::DEFAULT_THRESHOLD,
            ])->saveQuietly();

            $item->movements()->create([
                'change' => $quantity,
                'quantity_after' => $quantity,
                'note' => 'Opening stock (demo)',
            ]);
        });
    }
}
