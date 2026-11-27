@php
    use App\Support\Money;

    /** @var array $b pricing engine result for this line */
    // What one piece costs us: the artwork plus materials at cost (no markup).
    $costOf = fn (array $c): int => ($c['key'] ?? null) === 'artwork' ? (int) $c['sell'] : (int) $c['cost'];
    $cost = array_sum(array_map($costOf, $b['components'] ?? []));

    // Whole rupees everywhere: the quote rounds to ₹1, and paise only add noise when reading.
    $inr = fn (int $paise): string => '₹'.number_format(round($paise / 100));

    // "₹850.00/m (Antique gold…, 40 mm profile)" → "₹850 per m"
    $rate = function (?string $text): string {
        $text = preg_replace('/\s*\([^)]*\)/', '', (string) $text);
        $text = preg_replace('/(\d)\.\d{2}\b/', '$1', $text);
        $text = preg_replace('/\/m²/', ' per m²', $text);
        $text = preg_replace('/\/m\b/', ' per m', $text);

        return trim($text);
    };
    $num = fn (string $s) => preg_replace_callback('/(\d+\.\d{3,})/', fn ($x) => rtrim(rtrim(number_format((float) $x[1], 2), '0'), '.'), $s);
    $after = fn (string $s) => trim(str_contains($s, '=') ? substr($s, strrpos($s, '=') + 1) : $s);

    // One plain sentence: how much of it, at what rate.
    $how = function (array $c) use ($rate, $num, $after): string {
        $m = (string) ($c['measure'] ?? '');
        $key = (string) ($c['key'] ?? '');
        $r = $rate($c['rate'] ?? null);

        if (str_starts_with($key, 'frame')) {
            preg_match_all('/=\s*([\d.]+\s*m)\b/', $m, $all);
            $length = $num(end($all[1]) ?: $m);
            preg_match('/(\d+)% waste/', $m, $waste);
            $extra = ! empty($waste) ? ' (incl. corners and '.$waste[1].'% extra for cutting)' : ' (incl. corners)';

            return $length.' of moulding'.($r ? ' at '.$r : '').$extra;
        }
        if (str_contains($m, '· edge') && str_contains($r, '+')) {
            [$area, $edge] = array_map('trim', explode('· edge', $m, 2));
            [$areaRate, $edgeRate] = array_map('trim', explode('+', $r, 2));

            return $num($after($area)).' at '.$areaRate.', plus '.$num($after($edge)).' of edge at '.str_replace(' of edge', '', $edgeRate);
        }
        // "100 × 93 cm = 0.93 m²" → "0.93 m² (100 × 93 cm) at ₹9,500 per m²"
        if (preg_match('/^([^=]+?)\s*=\s*([^=]+)$/u', $m, $p)) {
            return $num(trim($p[2])).' ('.trim($p[1]).')'.($r ? ' at '.$r : '');
        }

        return $num($m).($r ? ' at '.$r : '');
    };

    // A clear name for each part.
    $name = fn (array $c): string => match ((string) ($c['key'] ?? '')) {
        'substrate' => 'Surface — '.$c['label'],
        'glazing' => 'Glass — '.$c['label'],
        'mat' => 'Mat — '.$c['label'],
        default => (string) $c['label'],
    };

@endphp

<style>
    .pb { font-size: .875rem; line-height: 1.45; }
    .pb-item { display: flex; justify-content: space-between; gap: 1rem; padding: .5rem 0; border-top: 1px solid rgba(127, 127, 127, .15); }
    .pb-item:first-child { border-top: 0; padding-top: 0; }
    .pb-how { opacity: .6; font-size: .78rem; margin-top: .1rem; }
    .pb-amt { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .pb-total { font-weight: 600; border-top: 1px solid rgba(127, 127, 127, .35); }
    .pb-pending { padding: .5rem .75rem; margin-bottom: .75rem; border-radius: .5rem; background: rgba(245, 158, 11, .14); }
</style>

<div class="pb">
    @if (! empty($b['pending']))
        <div class="pb-pending"><strong>Not finished:</strong> {{ implode(' · ', $b['pending']) }}</div>
    @endif

    @foreach ($b['components'] ?? [] as $c)
        <div class="pb-item">
            <div>
                <div>{{ $name($c) }}</div>
                <div class="pb-how">{{ $how($c) }}</div>
            </div>
            <div class="pb-amt">{{ $inr($costOf($c)) }}</div>
        </div>
    @endforeach
    <div class="pb-item pb-total"><span>Cost per piece</span><span class="pb-amt">{{ $inr($cost) }}</span></div>
</div>
