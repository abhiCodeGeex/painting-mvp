@php
    use App\Support\Money;

    /** @var array $b pricing engine result for this line */
    /** @var int $labour labour per piece entered on the quote line (paise) */
    /** @var ?int $quoted unit price currently on the quote line (paise) */
    /** @var list<array{name: string, amount: int}> $work work after the artwork (paise per piece) */
    $labour = (int) ($labour ?? 0);
    $work = $work ?? [];
    $engine = (int) ($b['unit_price'] ?? 0);
    $cost = (int) ($b['unit_cost'] ?? 0);
    $calculated = $engine + $labour + array_sum(array_column($work, 'amount'));
    $markup = rtrim(rtrim(number_format((float) ($b['markup_percent'] ?? 0), 2), '0'), '.');

    $muted = 'opacity:.65;font-size:.8rem;line-height:1.35';
    $rule = 'border-top:1px solid rgba(127,127,127,.25)';
    $cell = 'padding:.4rem .75rem .4rem 0;vertical-align:top';
    $num = 'text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;padding:.4rem 0;vertical-align:top';
    $margin = fn (int $price) => Money::format($price - $cost).($price > 0 ? ' ('.round(($price - $cost) / $price * 100, 1).'%)' : '');
@endphp

<div style="font-size:.875rem;line-height:1.45;overflow-x:auto">
    @if (! empty($b['pending']))
        <div style="margin-bottom:.5rem;padding:.4rem .6rem;border-radius:.375rem;background:rgba(245,158,11,.12)">
            Still to decide: {{ implode(' · ', $b['pending']) }}
        </div>
    @endif

    <table style="width:100%;border-collapse:collapse">
        <thead>
            <tr style="{{ $muted }}">
                <th style="text-align:left;font-weight:500;padding:0 .75rem .25rem 0">Component</th>
                <th style="text-align:left;font-weight:500;padding:0 .75rem .25rem 0">How it's measured</th>
                <th style="text-align:left;font-weight:500;padding:0 .75rem .25rem 0">Rate</th>
                <th style="text-align:right;font-weight:500;padding:0 0 .25rem">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($b['components'] ?? [] as $row)
                <tr style="{{ $rule }}">
                    <td style="{{ $cell }}">
                        <div style="font-weight:500">{{ $row['label'] }}</div>
                        <div style="{{ $muted }}">
                            {{ ($row['marked_up'] ?? $row['cost'] > 0)
                                ? 'Cost '.Money::format((int) $row['cost']).' + '.$markup.'% markup'
                                : 'Selling price' }}
                        </div>
                    </td>
                    <td style="{{ $cell }};{{ $muted }}">{{ $row['measure'] ?? '' }}</td>
                    <td style="{{ $cell }};{{ $muted }}">{{ $row['rate'] ?? '' }}</td>
                    <td style="{{ $num }}">{{ Money::format((int) $row['sell']) }}</td>
                </tr>
            @endforeach

            <tr style="{{ $rule }};font-weight:500">
                <td style="{{ $cell }}" colspan="3">Subtotal per piece</td>
                <td style="{{ $num }}">{{ Money::format((int) ($b['unit_sell'] ?? 0)) }}</td>
            </tr>

            @foreach ($b['adjustments'] ?? [] as $adj)
                <tr>
                    <td style="padding:.15rem 0" colspan="3">{{ $adj['label'] }}</td>
                    <td style="{{ $num }};padding:.15rem 0">{{ $adj['amount'] < 0 ? '− '.Money::format(-$adj['amount']) : '+ '.Money::format($adj['amount']) }}</td>
                </tr>
            @endforeach

            @if ($labour > 0)
                <tr>
                    <td style="padding:.15rem 0" colspan="3">
                        Labour (entered on quote)
                        <span style="{{ $muted }}">· per piece, no markup</span>
                    </td>
                    <td style="{{ $num }};padding:.15rem 0">+ {{ Money::format($labour) }}</td>
                </tr>
            @endif

            @foreach ($work as $item)
                <tr>
                    <td style="padding:.15rem 0" colspan="3">
                        Work: {{ $item['name'] }}
                        <span style="{{ $muted }}">· after the artwork, per piece</span>
                    </td>
                    <td style="{{ $num }};padding:.15rem 0">+ {{ Money::format($item['amount']) }}</td>
                </tr>
            @endforeach

            <tr style="{{ $rule }};font-weight:600">
                <td style="{{ $cell }}" colspan="3">Calculated unit price</td>
                <td style="{{ $num }}">{{ Money::format($calculated) }}</td>
            </tr>
            <tr style="{{ $muted }}">
                <td style="padding:0 0 .2rem" colspan="3">Margin over material cost {{ Money::format($cost) }}</td>
                <td style="{{ $num }};padding:0 0 .2rem">{{ $margin($calculated) }}</td>
            </tr>

            @if ($quoted !== null && $quoted !== $calculated)
                <tr style="font-weight:500">
                    <td style="padding:.2rem 0" colspan="3">Quoted unit price (changed by hand)</td>
                    <td style="{{ $num }};padding:.2rem 0">{{ Money::format($quoted) }}</td>
                </tr>
                <tr style="{{ $muted }}">
                    <td style="padding:0" colspan="3">Margin at quoted price</td>
                    <td style="{{ $num }};padding:0">{{ $margin($quoted) }}</td>
                </tr>
            @endif
        </tbody>
    </table>
</div>
