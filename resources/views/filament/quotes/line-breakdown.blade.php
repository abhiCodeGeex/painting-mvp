@php
    use App\Support\Money;

    /** @var array $b pricing engine result for this line */
    /** @var int $labour labour per piece entered on the quote line (paise) */
    /** @var ?int $quoted unit price currently on the quote line (paise) */
    $labour = (int) ($labour ?? 0);
    $engine = (int) ($b['unit_price'] ?? 0);
    $cost = (int) ($b['unit_cost'] ?? 0);
    $calculated = $engine + $labour;
    $charged = ($quoted !== null && $quoted !== $calculated) ? $quoted : $calculated;

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

    $markup = rtrim(rtrim(number_format((float) ($b['markup_percent'] ?? 0), 2), '0'), '.');
    $profit = $charged - $cost;
    $profitPct = $charged > 0 ? round($profit / $charged * 100) : 0;
@endphp

<style>
    .pb { display: grid; gap: 1.25rem 2rem; grid-template-columns: minmax(0, 1fr); font-size: .875rem; line-height: 1.45; }
    @container (min-width: 40rem) { .pb { grid-template-columns: minmax(0, 1fr) 15rem; } }
    .pb-wrap { container-type: inline-size; }
    .pb-eyebrow { font-size: .7rem; text-transform: uppercase; letter-spacing: .06em; opacity: .55; margin-bottom: .5rem; }
    .pb-item { display: flex; justify-content: space-between; gap: 1rem; padding: .55rem 0; border-top: 1px solid rgba(127, 127, 127, .15); }
    .pb-eyebrow + .pb-item { border-top: 0; padding-top: 0; }
    .pb-how { opacity: .6; font-size: .78rem; margin-top: .1rem; }
    .pb-amt { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .pb-card { align-self: start; border-radius: .75rem; padding: 1rem; background: rgba(127, 127, 127, .07); display: grid; gap: .4rem; }
    .pb-line { display: flex; justify-content: space-between; gap: .75rem; }
    .pb-total { font-size: 1.35rem; font-weight: 600; font-variant-numeric: tabular-nums; line-height: 1.2; }
    .pb-sep { border-top: 1px solid rgba(127, 127, 127, .2); margin: .35rem 0; }
    .pb-note { opacity: .6; font-size: .75rem; }
    .pb-pending { grid-column: 1 / -1; padding: .5rem .75rem; border-radius: .5rem; background: rgba(245, 158, 11, .14); }
</style>

<div class="pb-wrap">
    <div class="pb">
        @if (! empty($b['pending']))
            <div class="pb-pending"><strong>Not finished:</strong> {{ implode(' · ', $b['pending']) }}</div>
        @endif

        {{-- What one piece is made of. --}}
        <div>
            <div class="pb-eyebrow">What goes into one piece</div>
            @foreach ($b['components'] ?? [] as $c)
                <div class="pb-item">
                    <div>
                        <div>{{ $name($c) }}</div>
                        <div class="pb-how">{{ $how($c) }}</div>
                    </div>
                    <div class="pb-amt">{{ $inr((int) $c['sell']) }}</div>
                </div>
            @endforeach
        </div>

        {{-- The price, and what we keep. --}}
        <div class="pb-card">
            <div class="pb-eyebrow" style="margin-bottom:0">Price per piece</div>
            <div class="pb-total">{{ $inr($charged) }}</div>
            @if ($charged !== $calculated)
                <div class="pb-note">Typed by hand · calculated {{ $inr($calculated) }}</div>
            @endif

            <div class="pb-sep"></div>
            <div class="pb-line"><span>Artwork &amp; materials</span><span class="pb-amt">{{ $inr($engine) }}</span></div>
            <div class="pb-line"><span>Labour</span><span class="pb-amt">{{ $labour > 0 ? '+ '.$inr($labour) : '—' }}</span></div>

            <div class="pb-sep"></div>
            <div class="pb-line"><span>Our cost</span><span class="pb-amt">{{ $inr($cost) }}</span></div>
            <div class="pb-line" style="font-weight:600;color:{{ $profit < 0 ? '#dc2626' : 'inherit' }}">
                <span>Profit</span><span class="pb-amt">{{ $inr($profit) }} · {{ $profitPct }}%</span>
            </div>
            @if ((float) $markup > 0)
                <div class="pb-note" style="margin-top:.25rem">Material rates shown are our cost; amounts include our {{ $markup }}% margin.</div>
            @endif
        </div>
    </div>
</div>
