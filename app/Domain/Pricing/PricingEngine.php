<?php

namespace App\Domain\Pricing;

use App\Enums\ArtworkPricingModel;
use App\Enums\PricingType;
use App\Enums\Rounding;
use App\Models\Artwork;
use App\Models\ComplexityLevel;
use App\Models\Finishing;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\Medium;
use App\Models\QualityTier;
use App\Models\Substrate;
use App\Settings\PricingSettings;
use Carbon\CarbonInterface;

/**
 * Prices one artwork line from the masters and pricing rules (spec §7).
 *
 * Used for the customer's live estimate and to prefill admin quotes, so both always agree.
 * All money is integer paise, all sizes integer mm.
 */
class PricingEngine
{
    public function __construct(private readonly PricingSettings $rules) {}

    /**
     * @return array{
     *     dimensions: array<string, int|float>,
     *     components: list<array{key: string, label: string, cost: int, sell: int}>,
     *     pending: list<string>,
     *     complete: bool,
     *     unit_cost: int,
     *     unit_sell: int,
     *     quantity: int,
     *     quantity_discount_percent: float,
     *     rush_fee_percent: float,
     *     unit_price: int,
     *     line_total: int,
     *     estimate: array{unit_low: int, unit_high: int, line_low: int, line_high: int}
     * }
     */
    public function price(LineConfig $c, ?CarbonInterface $requiredBy = null, ?CarbonInterface $today = null): array
    {
        $artwork = $c->artworkId ? Artwork::with('sizePrices')->find($c->artworkId) : null;
        $tier = $c->qualityTierId ? QualityTier::find($c->qualityTierId) : null;
        $medium = $c->mediumId ? Medium::find($c->mediumId) : null;
        $substrate = $c->substrateId ? Substrate::find($c->substrateId) : null;
        $frame = $c->frameId ? Frame::find($c->frameId) : null;
        $mat = $c->matId && (! $substrate || $substrate->allows_mat) ? Mat::find($c->matId) : null;
        $glazing = $c->glazingId ? Glazing::find($c->glazingId) : null;
        $finishings = $c->finishingIds ? Finishing::whereIn('id', $c->finishingIds)->orderBy('sort_order')->get() : collect();

        $dims = $this->dimensions($c, $frame, $mat);
        $areaM2 = $dims['area_m2'];
        $imagePerimeterM = 2 * ($dims['image_w'] + $dims['image_h']) / 1000;

        $mediumFactor = (float) ($medium?->price_factor ?? 1);
        $tierFactor = (float) ($tier?->price_factor ?? 1);

        $components = [];
        $pending = [];
        $add = function (string $key, string $label, int $cost, int $sell = 0) use (&$components): void {
            if ($cost > 0 || $sell > 0) {
                $components[] = ['key' => $key, 'label' => $label, 'cost' => $cost, 'sell' => $sell];
            }
        };

        // ---- the artwork itself (already a selling price, no markup) ----
        $commissionLow = $commissionHigh = null;
        switch ($c->sourceType) {
            case 'catalog':
                if (! $artwork) {
                    $pending[] = 'Choose an artwork';
                    break;
                }
                $add('artwork', $artwork->title, 0, $this->catalogPrice($artwork, $dims['image_w'], $dims['image_h'], $mediumFactor * $tierFactor));
                break;

            case 'custom_creation':
                $base = $areaM2 * $this->rules->commission_price_per_m2 * $mediumFactor * $tierFactor;
                $levels = ComplexityLevel::query()->where('is_active', true)->pluck('price_factor')->map(fn ($f) => (float) $f);
                $chosen = $c->complexityLevelId ? ComplexityLevel::find($c->complexityLevelId) : null;
                $factor = $chosen ? (float) $chosen->price_factor : 1.0;
                $add('artwork', 'New artwork by our artist'.($chosen ? " ({$chosen->name})" : ''), 0, (int) round($base * $factor));
                if (! $chosen && $levels->isNotEmpty()) {
                    // Until we judge the complexity, show the full possible range.
                    $commissionLow = (int) round($base * $levels->min());
                    $commissionHigh = (int) round($base * $levels->max());
                    $pending[] = 'Artist fee depends on the detail of the design';
                }
                break;

            case 'own_artwork':
                // Customer supplies the art; reproduction is covered by the print surface below.
                break;
        }

        // ---- materials & work (cost, marked up) ----
        $framingOnly = $c->sourceType === 'own_artwork' && $c->ownService !== 'reproduce_and_frame';
        if ($substrate && ! $framingOnly) {
            $add('substrate', $substrate->name, (int) round($areaM2 * $substrate->cost_per_m2 + $imagePerimeterM * $substrate->cost_per_perimeter_m));
        } elseif (! $substrate && ! $framingOnly) {
            $pending[] = 'Surface to be advised';
        }

        if ($frame) {
            $lengthM = ($dims['perimeter_mm'] + 8 * $frame->profile_width_mm) / 1000;
            $add('frame', "Frame: {$frame->name}", (int) round($lengthM * (1 + (float) $frame->waste_percent / 100) * $frame->price_per_m));
        } elseif ($c->frameAdvice) {
            $pending[] = 'Frame to be advised';
        }

        if ($mat) {
            $matArea = $dims['mat_outer_w'] * $dims['mat_outer_h'] / 1_000_000;
            $add('mat', "Mat: {$mat->name}", (int) round($matArea * $mat->cost_per_m2) + $mat->cut_fee);
        }

        if ($glazing) {
            $glassArea = $dims['rebate_w'] * $dims['rebate_h'] / 1_000_000;
            $add('glazing', $glazing->name, (int) round($glassArea * $glazing->cost_per_m2));
        }

        foreach ($finishings as $f) {
            $amount = match ($f->pricing_type) {
                PricingType::PerSquareMetre => $areaM2 * $f->cost,
                PricingType::PerPerimeterMetre => $dims['perimeter_mm'] / 1000 * $f->cost,
                default => $f->cost,
            };
            $add('finishing_'.$f->id, $f->name, (int) round($amount));
        }

        if ($frame || $mat) {
            $add('labour', 'Framing work', (int) round($this->rules->labour_fixed_per_piece + $dims['perimeter_mm'] / 1000 * $this->rules->labour_per_perimeter_m));
        }

        // ---- cost -> selling price ----
        $markup = 1 + $this->rules->default_markup_percent / 100;
        foreach ($components as &$row) {
            if ($row['sell'] === 0) {
                $row['sell'] = (int) round($row['cost'] * $markup);
            }
        }
        unset($row);

        $unitCost = array_sum(array_column($components, 'cost'));
        $unitSell = array_sum(array_column($components, 'sell'));

        $qtyDiscount = $this->quantityDiscount($c->quantity);
        $rush = $this->rushFee($requiredBy, $today);
        $adjust = (1 - $qtyDiscount / 100) * (1 + $rush / 100);

        $unitPrice = $this->round($unitSell * $adjust);

        // Indicative range shown to the customer.
        if ($commissionLow !== null) {
            $rest = $unitSell - ($components[0]['sell'] ?? 0);
            $low = $this->round(($rest + $commissionLow) * $adjust);
            $high = $this->round(($rest + $commissionHigh) * $adjust);
        } else {
            $spread = $this->rules->estimate_range_percent / 100;
            $low = $this->round($unitPrice * (1 - $spread));
            $high = $this->round($unitPrice * (1 + $spread));
        }

        return [
            'dimensions' => $dims,
            'components' => $components,
            'pending' => $pending,
            'complete' => $pending === [] && $unitSell > 0,
            'unit_cost' => $unitCost,
            'unit_sell' => $unitSell,
            'quantity' => $c->quantity,
            'quantity_discount_percent' => $qtyDiscount,
            'rush_fee_percent' => $rush,
            'unit_price' => $unitPrice,
            'line_total' => $unitPrice * $c->quantity,
            'estimate' => [
                'unit_low' => $low,
                'unit_high' => $high,
                'line_low' => $low * $c->quantity,
                'line_high' => $high * $c->quantity,
            ],
        ];
    }

    /**
     * Picture, mat and frame sizes (spec §7.1).
     *
     * @return array<string, int|float>
     */
    public function dimensions(LineConfig $c, ?Frame $frame, ?Mat $mat): array
    {
        $border = $mat ? ($c->matBorderMm ?? $mat->default_border_mm) : 0;
        $bottom = $mat ? $mat->bottom_weight_mm : 0;
        $allowance = $frame ? $this->rules->rebate_allowance_mm : 0;
        $profile = $frame?->profile_width_mm ?? 0;

        if ($c->sizeReference === 'outer') {
            $imageW = max(50, $c->widthMm - 2 * ($profile + $allowance + $border));
            $imageH = max(50, $c->heightMm - 2 * ($profile + $allowance + $border) - $bottom);
        } else {
            $imageW = $c->widthMm;
            $imageH = $c->heightMm;
        }

        $matOuterW = $imageW + 2 * $border;
        $matOuterH = $imageH + 2 * $border + $bottom;
        $rebateW = $matOuterW + 2 * $allowance;
        $rebateH = $matOuterH + 2 * $allowance;

        return [
            'image_w' => $imageW,
            'image_h' => $imageH,
            'mat_border' => $border,
            'mat_outer_w' => $matOuterW,
            'mat_outer_h' => $matOuterH,
            'rebate_w' => $rebateW,
            'rebate_h' => $rebateH,
            'outer_w' => $rebateW + 2 * $profile,
            'outer_h' => $rebateH + 2 * $profile,
            'perimeter_mm' => 2 * ($rebateW + $rebateH),
            'area_m2' => round($imageW * $imageH / 1_000_000, 4),
        ];
    }

    /** Catalog price: exact size price, else per m², else scaled from the nearest listed size. */
    private function catalogPrice(Artwork $artwork, int $w, int $h, float $factor): int
    {
        $exact = $artwork->sizePrices->first(fn ($p) => ($p->width_mm === $w && $p->height_mm === $h) || ($p->width_mm === $h && $p->height_mm === $w));
        if ($exact) {
            return (int) $exact->price;
        }

        $area = $w * $h / 1_000_000;
        if ($artwork->pricing_model === ArtworkPricingModel::PerSquareMetre && $artwork->price_per_m2) {
            return (int) round($area * $artwork->price_per_m2 * $factor);
        }

        $nearest = $artwork->sizePrices->sortBy(fn ($p) => abs($p->width_mm * $p->height_mm - $w * $h))->first();
        if ($nearest) {
            return (int) round($nearest->price * ($w * $h) / ($nearest->width_mm * $nearest->height_mm));
        }

        return $artwork->price_per_m2 ? (int) round($area * $artwork->price_per_m2 * $factor) : 0;
    }

    public function quantityDiscount(int $quantity): float
    {
        $discount = 0.0;
        foreach (collect($this->rules->quantity_breaks)->sortBy('min_qty') as $break) {
            if ($quantity >= (int) $break['min_qty']) {
                $discount = (float) $break['discount_percent'];
            }
        }

        return $discount;
    }

    public function rushFee(?CarbonInterface $requiredBy, ?CarbonInterface $today = null): float
    {
        if (! $requiredBy) {
            return 0.0;
        }
        $today ??= now();

        return $today->startOfDay()->diffInDays($requiredBy->copy()->startOfDay(), false) < $this->rules->rush_threshold_days
            ? $this->rules->rush_fee_percent
            : 0.0;
    }

    public function round(float $paise): int
    {
        $step = match (Rounding::tryFrom($this->rules->rounding)) {
            Rounding::Rupee => 100,
            Rounding::FiveRupees => 500,
            Rounding::TenRupees => 1000,
            default => 1,
        };

        return (int) (round($paise / $step) * $step);
    }
}
