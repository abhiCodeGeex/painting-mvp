@php
    use App\Support\Money;

    /** @var array $t live totals from QuoteResource::liveTotals() */
    /** @var ?array $estimate what the customer saw in the builder, incl. GST */
    $inr = fn (int $paise): string => '₹'.number_format(round($paise / 100));
    $pct = fn (float $n): string => rtrim(rtrim(number_format($n, 2), '0'), '.');
    $half = $pct($t['rate'] / 2);
    $profit = $t['taxable'] - $t['cost'];
    $profitPct = $t['taxable'] > 0 ? round($profit / $t['taxable'] * 100, 1) : null;
    $outside = $estimate && ($t['grand_total'] < $estimate['low'] || $t['grand_total'] > $estimate['high']);
@endphp

<style>
    .qt { font-size: .875rem; line-height: 1.4; display: grid; gap: .45rem; font-variant-numeric: tabular-nums; }
    .qt-row { display: flex; justify-content: space-between; gap: 1rem; }
    .qt-row > span:last-child { white-space: nowrap; }
    .qt-muted { opacity: .6; }
    .qt-sep { border-top: 1px solid rgba(127, 127, 127, .2); margin: .2rem 0; }
    .qt-grand { font-size: 1.25rem; font-weight: 600; align-items: baseline; }
    .qt-grand small { font-size: .75rem; font-weight: 400; opacity: .6; }
    .qt-staff { border-radius: .6rem; padding: .6rem .75rem; background: rgba(127, 127, 127, .07); display: grid; gap: .3rem; margin-top: .25rem; }
    .qt-eyebrow { font-size: .68rem; text-transform: uppercase; letter-spacing: .06em; opacity: .55; }
    .qt-warn { color: #b45309; }
    .qt-loss { color: #dc2626; }
</style>

<div class="qt">
    <div class="qt-row"><span>Artworks</span><span>{{ Money::format($t['items']) }}</span></div>
    <div class="qt-row"><span>Extras</span><span>{{ Money::format($t['extras']) }}</span></div>
    @if ($t['discount'] > 0)
        <div class="qt-row qt-muted"><span>Subtotal</span><span>{{ Money::format($t['subtotal']) }}</span></div>
        <div class="qt-row"><span>Discount{{ $t['discount_percent'] > 0 ? ' ('.$pct($t['discount_percent']).'%)' : '' }}</span><span>− {{ Money::format($t['discount']) }}</span></div>
    @endif
    <div class="qt-sep"></div>
    <div class="qt-row"><span>Before GST</span><span>{{ Money::format($t['taxable']) }}</span></div>
    @if ($t['rate'] <= 0)
        <div class="qt-row qt-muted"><span>GST</span><span>Exempt</span></div>
    @elseif ($t['intra_state'])
        <div class="qt-row qt-muted"><span>CGST {{ $half }}%</span><span>+ {{ Money::format($t['cgst']) }}</span></div>
        <div class="qt-row qt-muted"><span>SGST {{ $half }}%</span><span>+ {{ Money::format($t['sgst']) }}</span></div>
    @else
        <div class="qt-row qt-muted"><span>IGST {{ $pct($t['rate']) }}%</span><span>+ {{ Money::format($t['igst']) }}</span></div>
    @endif
    <div class="qt-sep"></div>
    <div class="qt-row qt-grand"><span>Total <small>incl. GST</small></span><span>{{ Money::format($t['grand_total']) }}</span></div>
    @if ($estimate)
        <div class="{{ $outside ? 'qt-warn' : 'qt-muted' }}" style="font-size:.78rem">
            Customer saw {{ $estimate['low'] === $estimate['high'] ? $inr($estimate['low']) : $inr($estimate['low']).' – '.$inr($estimate['high']) }}{{ $outside ? ($t['grand_total'] > $estimate['high'] ? ' · this total is higher' : ' · this total is lower') : '' }}
        </div>
    @endif

    <div class="qt-staff">
        <div class="qt-eyebrow">Staff only</div>
        <div class="qt-row"><span>Our cost</span><span>{{ Money::format($t['cost']) }}</span></div>
        <div class="qt-row {{ $profit < 0 ? 'qt-loss' : '' }}" style="font-weight:600">
            <span>Profit</span><span>{{ Money::format($profit) }}{{ $profitPct !== null ? ' · '.$profitPct.'%' : '' }}</span>
        </div>
        <div class="qt-muted" style="font-size:.72rem">Cost = artwork + materials + labour. Profit = before GST − cost.</div>
    </div>
</div>
