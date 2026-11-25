<?php

namespace App\Domain\Pricing;

use App\Enums\ArtworkPricingModel;
use App\Enums\PricingType;
use App\Enums\Rounding;
use App\Models\Artist;
use App\Models\Artwork;
use App\Models\Finishing;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\Medium;
use App\Models\QualityTier;
use App\Models\Substrate;
use App\Settings\PricingSettings;
use App\Support\Money;
use Carbon\CarbonInterface;

/**
 * Prices one artwork line from the masters and pricing rules (spec §7).
 *
 * Used for the customer's live estimate and to prefill admin quotes, so both always agree.
 * All money is integer paise, all sizes integer mm.
 *
 * Every component also says how it was measured ("measure") and which master rate was used ("rate"),
 * so staff can check the quote against the masters. Those two strings are staff-only.
 */
class PricingEngine
{
    public function __construct(private readonly PricingSettings $rules) {}

    /**
     * @return array{
     *     dimensions: array<string, int|float>,
     *     components: list<array{key: string, label: string, measure: string, rate: string, marked_up: bool, cost: int, sell: int, amount: int}>,
     *     pending: list<string>,
     *     complete: bool,
     *     unit_cost: int,
     *     unit_sell: int,
     *     markup_percent: float,
     *     quantity: int,
     *     quantity_discount_percent: float,
     *     rush_fee_percent: float,
     *     adjustments: list<array{key: string, label: string, amount: int}>,
     *     unit_price: int,
     *     unit_margin: int,
     *     margin_percent: ?float,
     *     line_total: int,
     *     estimate: array{unit_low: int, unit_high: int, line_low: int, line_high: int}
     * }
     */
    public function price(LineConfig $c, ?CarbonInterface $requiredBy = null, ?CarbonInterface $today = null): array
    {
        $artwork = $c->artworkId ? Artwork::with(['sizePrices', 'artist'])->find($c->artworkId) : null;
        $tier = $c->qualityTierId ? QualityTier::find($c->qualityTierId) : null;
        $medium = $c->mediumId ? Medium::find($c->mediumId) : null;
        $substrate = $c->substrateId ? Substrate::find($c->substrateId) : null;
        $frameLayers = $this->frameLayers($c);
        $frame = $frameLayers[0]['frame'] ?? null;
        // Mat and glass only go inside a frame: with no frame (and no frame advice asked for) they are not charged.
        $framed = $frame || $c->frameAdvice;
        $mat = $framed && $c->matId && (! $substrate || $substrate->allows_mat) ? Mat::find($c->matId) : null;
        $glazing = $framed && $c->glazingId ? Glazing::find($c->glazingId) : null;
        $finishings = $c->finishingIds ? Finishing::whereIn('id', $c->finishingIds)->orderBy('sort_order')->get() : collect();

        $dims = $this->dimensions($c, $frame, $mat, $frameLayers);
        $areaM2 = $dims['area_m2'];
        $imagePerimeterM = 2 * ($dims['image_w'] + $dims['image_h']) / 1000;
        $perimeterM = $dims['perimeter_mm'] / 1000;
        $imageSize = $this->size($dims['image_w'], $dims['image_h']).' = '.$this->m2($areaM2);

        $mediumFactor = (float) ($medium?->price_factor ?? 1);
        $tierFactor = (float) ($tier?->price_factor ?? 1);
        $factorText = $this->factor($mediumFactor, $medium?->name).$this->factor($tierFactor, $tier?->name ? "{$tier->name} tier" : null);

        $markupPercent = (float) $this->rules->default_markup_percent;
        $markup = 1 + $markupPercent / 100;

        $components = [];
        $pending = [];
        // $sell > 0: already a selling price (no markup). Otherwise the cost is marked up below.
        $add = function (string $key, string $label, string $measure, string $rate, int $cost, int $sell = 0) use (&$components): void {
            if ($cost > 0 || $sell > 0) {
                $components[] = ['key' => $key, 'label' => $label, 'measure' => $measure, 'rate' => $rate,
                    'marked_up' => $sell === 0, 'cost' => $cost, 'sell' => $sell];
            }
        };

        // ---- the artwork itself (already a selling price, no markup) ----
        $commissionRange = null;
        $artworkSell = 0;
        switch ($c->sourceType) {
            case 'catalog':
                if (! $artwork) {
                    $pending[] = 'Choose an artwork';
                    break;
                }
                // The artwork's own price if it has one, else its artist's fee per m².
                [$artworkSell, $measure, $rate] = $this->hasOwnPrice($artwork)
                    ? $this->catalogPrice($artwork, $dims['image_w'], $dims['image_h'], $mediumFactor * $tierFactor, $factorText)
                    : $this->artistFeePrice($artwork, $areaM2, $imageSize, $mediumFactor * $tierFactor, $factorText);
                $add('artwork', $artwork->title, $measure, $rate, 0, $artworkSell);
                break;

            case 'custom_creation':
                // Priced by the artist we choose on the quote: their fee per m² × area × medium × tier.
                $base = $areaM2 * $mediumFactor * $tierFactor;
                $artist = $c->artistId ? Artist::find($c->artistId) : null;
                if ($artist) {
                    if ($artist->fee_per_m2 <= 0) {
                        $pending[] = "Set a fee per m² for {$artist->name}";
                    }
                    $artworkSell = (int) round($base * $artist->fee_per_m2);
                    $add('artwork', "New artwork by {$artist->name}", $imageSize,
                        $this->money($artist->fee_per_m2)."/m² (artist fee: {$artist->name})".$factorText, 0, $artworkSell);
                    break;
                }

                $pending[] = 'Choose an artist';
                // Until we choose the artist, the customer sees the range across our artists' fees.
                $fees = Artist::query()->where('is_active', true)->where('fee_per_m2', '>', 0)->pluck('fee_per_m2');
                if ($fees->isNotEmpty()) {
                    $commissionRange = [(int) round($base * $fees->min()), (int) round($base * $fees->max())];
                }
                break;

            case 'own_artwork':
                // Customer supplies the art; reproduction is covered by the print surface below.
                break;
        }

        // ---- materials & work (cost, marked up) ----
        $framingOnly = $c->sourceType === 'own_artwork' && $c->ownService !== 'reproduce_and_frame';
        if ($substrate && ! $framingOnly) {
            $edge = $imagePerimeterM * $substrate->cost_per_perimeter_m;
            $add('substrate', $substrate->name,
                $imageSize.($substrate->cost_per_perimeter_m ? ' · edge 2 × ('.$this->cmNum($dims['image_w']).' + '.$this->cmNum($dims['image_h']).') cm = '.$this->m($imagePerimeterM) : ''),
                $this->money($substrate->cost_per_m2).'/m²'.($substrate->cost_per_perimeter_m ? ' + '.$this->money($substrate->cost_per_perimeter_m).'/m of edge' : ''),
                (int) round($areaM2 * $substrate->cost_per_m2 + $edge));
        } elseif (! $substrate && ! $framingOnly) {
            $pending[] = 'Surface to be advised';
        }

        if ($frame) {
            // One row per moulding per layer (a single plain frame keeps the 'frame' row).
            foreach ($this->frameRows($frameLayers, $dims['layers']) as [$key, $label, $measure, $rate, $cost]) {
                $add($key, $label, $measure, $rate, $cost);
            }
        } elseif ($c->frameAdvice) {
            $pending[] = 'Frame to be advised';
        }

        if ($mat) {
            $matArea = $dims['mat_outer_w'] * $dims['mat_outer_h'] / 1_000_000;
            $add('mat', "Mat: {$mat->name}",
                $this->size($dims['mat_outer_w'], $dims['mat_outer_h']).' ('.$dims['mat_border'].' mm border) = '.$this->m2($matArea),
                $this->money($mat->cost_per_m2).'/m²'.($mat->cut_fee ? ' + '.$this->money($mat->cut_fee).' cutting' : ''),
                (int) round($matArea * $mat->cost_per_m2) + $mat->cut_fee);
        }

        if ($glazing) {
            $glassArea = $dims['rebate_w'] * $dims['rebate_h'] / 1_000_000;
            $add('glazing', $glazing->name,
                'Rebate '.$this->size($dims['rebate_w'], $dims['rebate_h']).' = '.$this->m2($glassArea),
                $this->money($glazing->cost_per_m2).'/m²',
                (int) round($glassArea * $glazing->cost_per_m2));
        }

        foreach ($finishings as $f) {
            [$amount, $measure, $rate] = match ($f->pricing_type) {
                PricingType::PerSquareMetre => [$areaM2 * $f->cost, $imageSize, $this->money($f->cost).'/m²'],
                PricingType::PerPerimeterMetre => [$perimeterM * $f->cost, 'Perimeter '.$this->m($perimeterM), $this->money($f->cost).'/m'],
                default => [$f->cost, '1 piece', $this->money($f->cost).' per piece'],
            };
            $add('finishing_'.$f->id, $f->name, $measure, $rate, (int) round($amount));
        }

        // Labour is not priced here: staff enter it per piece on the quote line.

        // ---- cost -> selling price ----
        foreach ($components as &$row) {
            if ($row['marked_up']) {
                $row['sell'] = (int) round($row['cost'] * $markup);
            }
            $row['amount'] = $row['sell'];
        }
        unset($row);

        $unitCost = array_sum(array_column($components, 'cost'));
        $unitSell = array_sum(array_column($components, 'sell'));

        $qtyDiscount = $this->quantityDiscount($c->quantity);
        $rush = $this->rushFee($requiredBy, $today);
        $adjust = (1 - $qtyDiscount / 100) * (1 + $rush / 100);

        $unitPrice = $this->round($unitSell * $adjust);

        $adjustments = [];
        $afterDiscount = $unitSell * (1 - $qtyDiscount / 100);
        if ($qtyDiscount > 0) {
            $adjustments[] = ['key' => 'quantity_discount', 'label' => "Quantity discount {$this->num($qtyDiscount)}% ({$c->quantity} pieces)",
                'amount' => (int) round($afterDiscount - $unitSell)];
        }
        if ($rush > 0) {
            $adjustments[] = ['key' => 'rush_fee', 'label' => "Rush fee {$this->num($rush)}% (needed within {$this->rules->rush_threshold_days} days)",
                'amount' => (int) round($afterDiscount * $rush / 100)];
        }
        $roundingDiff = $unitPrice - $unitSell - array_sum(array_column($adjustments, 'amount'));
        if ($roundingDiff !== 0) {
            $adjustments[] = ['key' => 'rounding', 'label' => 'Rounding ('.(Rounding::tryFrom($this->rules->rounding)?->getLabel() ?? 'none').')',
                'amount' => $roundingDiff];
        }

        // Indicative range shown to the customer.
        if ($commissionRange !== null) {
            $rest = $unitSell - $artworkSell;
            $low = $this->round(($rest + $commissionRange[0]) * $adjust);
            $high = $this->round(($rest + $commissionRange[1]) * $adjust);
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
            'markup_percent' => $markupPercent,
            'quantity' => $c->quantity,
            'quantity_discount_percent' => $qtyDiscount,
            'rush_fee_percent' => $rush,
            'adjustments' => $adjustments,
            'unit_price' => $unitPrice,
            'unit_margin' => $unitPrice - $unitCost,
            'margin_percent' => $unitPrice > 0 ? round(($unitPrice - $unitCost) / $unitPrice * 100, 1) : null,
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
     * Picture, mat and frame sizes (spec §7.1). With stacked frames, layer 1's opening is the rebate
     * (picture + mat + rebate allowance), each next layer's opening is the previous layer's outer size,
     * and outer_w/outer_h are the outside of the last layer. A split layer is as wide as the wider of
     * its two mouldings. rebate_w/h and perimeter_mm stay layer 1's (glass, mat, finishings).
     *
     * @param  list<array{frame: Frame, split: array{frame: Frame, mode: string}|null}>|null  $layers  null = just $frame
     * @return array<string, mixed>
     */
    public function dimensions(LineConfig $c, ?Frame $frame, ?Mat $mat, ?array $layers = null): array
    {
        $layers ??= $frame ? [['frame' => $frame, 'split' => null]] : [];
        $profiles = array_map(fn (array $l): int => $this->layerProfile($l), $layers);

        $border = $mat ? ($c->matBorderMm ?? $mat->default_border_mm) : 0;
        $bottom = $mat ? $mat->bottom_weight_mm : 0;
        $allowance = $layers ? $this->rules->rebate_allowance_mm : 0;
        $profile = array_sum($profiles);

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

        $outerW = $rebateW;
        $outerH = $rebateH;
        $layerDims = [];
        foreach ($profiles as $i => $p) {
            $layerDims[] = [
                'layer' => $i + 1,
                'opening_w' => $outerW,
                'opening_h' => $outerH,
                'profile_mm' => $p,
                'outer_w' => $outerW + 2 * $p,
                'outer_h' => $outerH + 2 * $p,
            ];
            $outerW += 2 * $p;
            $outerH += 2 * $p;
        }

        return [
            'image_w' => $imageW,
            'image_h' => $imageH,
            'mat_border' => $border,
            'mat_outer_w' => $matOuterW,
            'mat_outer_h' => $matOuterH,
            'rebate_w' => $rebateW,
            'rebate_h' => $rebateH,
            'outer_w' => $outerW,
            'outer_h' => $outerH,
            'perimeter_mm' => 2 * ($rebateW + $rebateH),
            'area_m2' => round($imageW * $imageH / 1_000_000, 4),
            'layers' => $layerDims,
        ];
    }

    /**
     * The line's frame layers with their mouldings loaded (innermost first). A layer whose frame is gone
     * is dropped; a split whose second frame is gone becomes a plain layer.
     *
     * @return list<array{frame: Frame, split: array{frame: Frame, mode: string}|null}>
     */
    private function frameLayers(LineConfig $c): array
    {
        $layers = FrameLayers::normalise($c->frameLayers, $c->frameId);
        if ($layers === []) {
            return [];
        }
        $frames = Frame::whereIn('id', FrameLayers::frameIds($layers))->get()->keyBy('id');

        $out = [];
        foreach ($layers as $layer) {
            if (! $primary = $frames->get($layer['frame_id'])) {
                continue;
            }
            $second = $layer['split'] ? $frames->get($layer['split']['frame_id']) : null;
            $out[] = ['frame' => $primary, 'split' => $second ? ['frame' => $second, 'mode' => $layer['split']['mode']] : null];
        }

        return $out;
    }

    /** A layer is as wide as its (wider) moulding. */
    private function layerProfile(array $layer): int
    {
        return max((int) $layer['frame']->profile_width_mm, (int) ($layer['split']['frame']->profile_width_mm ?? 0));
    }

    /**
     * Breakdown rows for the mouldings: per layer, length = opening perimeter + mitres (8 × profile) + waste.
     * A split layer shares the length: top & bottom / sides → 2 × width + 4 × profile and 2 × height + 4 × profile;
     * halves → width + height + 4 × profile each. Every moulding uses its own price, profile and waste.
     *
     * @param  list<array{frame: Frame, split: array{frame: Frame, mode: string}|null}>  $layers
     * @param  list<array<string, int>>  $dims  dimensions()['layers']
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: int}> key, label, measure, rate, cost
     */
    private function frameRows(array $layers, array $dims): array
    {
        $count = count($layers);
        $rows = [];
        // $base: how the length was measured, $baseM: metres before mitres, $mitres: share of the corners (8 = all four).
        $row = function (string $key, string $label, Frame $f, string $base, float $baseM, int $mitres) use (&$rows): void {
            $lengthM = $baseM + $mitres * $f->profile_width_mm / 1000;
            $waste = (float) $f->waste_percent;
            $orderedM = $lengthM * (1 + $waste / 100);
            $rows[] = [$key, $label,
                $base.' + mitres '.$mitres.' × '.$f->profile_width_mm.' mm = '.$this->m($lengthM)
                    .($waste > 0 ? ' + '.$this->num($waste).'% waste = '.$this->m($orderedM) : ''),
                $this->money($f->price_per_m).'/m ('.$f->name.', '.$f->profile_width_mm.' mm profile)',
                (int) round($orderedM * $f->price_per_m)];
        };

        foreach ($layers as $i => $layer) {
            $n = $i + 1;
            $d = $dims[$i];
            [$w, $h] = [$d['opening_w'], $d['opening_h']];
            $perimeterM = 2 * ($w + $h) / 1000;
            $opening = ($n === 1 ? 'Rebate ' : 'Opening ').$this->size($w, $h);
            $primary = $layer['frame'];
            $split = $layer['split'];

            if ($count === 1 && ! $split) {
                // A single plain frame: the same row as before stacked frames existed.
                $row('frame', "Frame: {$primary->name}", $primary, $opening.' → perimeter '.$this->m($perimeterM), $perimeterM, 8);

                continue;
            }

            $title = 'Frame '.$n.($count > 1 ? ($n === 1 ? ' (inner)' : ' (around frame '.($n - 1).')') : '');
            if (! $split) {
                $row('frame_'.$n, "{$title}: {$primary->name}", $primary, $opening.' → perimeter '.$this->m($perimeterM), $perimeterM, 8);

                continue;
            }

            $second = $split['frame'];
            $wider = $d['profile_mm'];
            $note = $primary->profile_width_mm !== $second->profile_width_mm
                ? ' · layer width = the wider moulding, '.$wider.' mm' : '';
            if ($split['mode'] === FrameLayers::SPLIT_HALVES) {
                $half = $this->cmNum($w).' + '.$this->cmNum($h).' cm';
                $row('frame_'.$n, "{$title} · top half: {$primary->name}", $primary,
                    $opening.' → top rail + upper halves of the sides '.$half, ($w + $h) / 1000, 4);
                $row('frame_'.$n.'_split', "{$title} · bottom half: {$second->name}", $second,
                    $opening.' → bottom rail + lower halves of the sides '.$half, ($w + $h) / 1000, 4);
            } else {
                $row('frame_'.$n, "{$title} · top & bottom: {$primary->name}", $primary,
                    $opening.' → top & bottom 2 × '.$this->cmNum($w).' cm', 2 * $w / 1000, 4);
                $row('frame_'.$n.'_split', "{$title} · sides: {$second->name}", $second,
                    $opening.' → sides 2 × '.$this->cmNum($h).' cm', 2 * $h / 1000, 4);
            }
            $rows[count($rows) - 2][2] .= $note;
        }

        return $rows;
    }

    /** Has the artwork master any price of its own (a listed size or a price per m²)? */
    private function hasOwnPrice(Artwork $artwork): bool
    {
        return $artwork->sizePrices->isNotEmpty() || (int) $artwork->price_per_m2 > 0;
    }

    /**
     * Catalog artwork with no price of its own: its artist's fee per m² × area × medium × tier.
     *
     * @return array{0: int, 1: string, 2: string} amount, how it was measured, rate used
     */
    private function artistFeePrice(Artwork $artwork, float $areaM2, string $imageSize, float $factor, string $factorText): array
    {
        $artist = $artwork->artist;
        $fee = (int) ($artist?->fee_per_m2 ?? 0);
        if ($fee === 0) {
            return [0, $imageSize, $artist ? "No price on the artwork and no fee set for {$artist->name}" : 'No price on the artwork and no artist'];
        }

        return [(int) round($areaM2 * $fee * $factor), $imageSize, $this->money($fee)."/m² (artist fee: {$artist->name})".$factorText];
    }

    /**
     * Catalog price: exact size price, else per m², else scaled from the nearest listed size.
     *
     * @return array{0: int, 1: string, 2: string} amount, how it was measured, rate used
     */
    private function catalogPrice(Artwork $artwork, int $w, int $h, float $factor, string $factorText): array
    {
        $size = $this->size($w, $h);
        $area = $w * $h / 1_000_000;

        $exact = $artwork->sizePrices->first(fn ($p) => ($p->width_mm === $w && $p->height_mm === $h) || ($p->width_mm === $h && $p->height_mm === $w));
        if ($exact) {
            return [(int) $exact->price, $size.' (listed size)', 'Listed price '.$this->money((int) $exact->price).' for this size (artwork master)'];
        }

        $perM2 = fn (): array => [(int) round($area * $artwork->price_per_m2 * $factor), $size.' = '.$this->m2($area),
            $this->money((int) $artwork->price_per_m2).'/m² (artwork master)'.$factorText];

        if ($artwork->pricing_model === ArtworkPricingModel::PerSquareMetre && $artwork->price_per_m2) {
            return $perM2();
        }

        $nearest = $artwork->sizePrices->sortBy(fn ($p) => abs($p->width_mm * $p->height_mm - $w * $h))->first();
        if ($nearest) {
            $nearestArea = $nearest->width_mm * $nearest->height_mm / 1_000_000;

            return [(int) round($nearest->price * ($w * $h) / ($nearest->width_mm * $nearest->height_mm)),
                $size.' = '.$this->m2($area).', scaled by area from listed '.$this->size($nearest->width_mm, $nearest->height_mm).' ('.$this->m2($nearestArea).')',
                'Listed '.$this->money((int) $nearest->price).' for that size × '.$this->num($area / $nearestArea, 3)];
        }

        return $artwork->price_per_m2 ? $perM2() : [0, $size, 'No price on the artwork master'];
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

    // ---- text helpers for the breakdown ----

    private function num(float $n, int $decimals = 2): string
    {
        $s = number_format($n, $decimals, '.', ',');

        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }

    private function cmNum(int $mm): string
    {
        return $this->num($mm / 10, 1);
    }

    private function size(int $wMm, int $hMm): string
    {
        return $this->cmNum($wMm).' × '.$this->cmNum($hMm).' cm';
    }

    private function m2(float $m2): string
    {
        return $this->num($m2, 4).' m²';
    }

    private function m(float $m): string
    {
        return $this->num($m, 3).' m';
    }

    private function money(int $paise): string
    {
        return Money::format($paise);
    }

    private function factor(float $factor, ?string $name, bool $always = false): string
    {
        return ($always || abs($factor - 1) > 0.0001) && $name !== null ? ' × '.$this->num($factor, 3)." ({$name})" : '';
    }
}
