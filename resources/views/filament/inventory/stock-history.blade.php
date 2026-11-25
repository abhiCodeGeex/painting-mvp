@php
    use App\Models\StockItem;

    /** @var StockItem $item */
    /** @var \Illuminate\Support\Collection<int, \App\Models\StockMovement> $movements */
    $cell = 'padding:8px 10px;border-top:1px solid rgba(127,127,127,.2);vertical-align:top;';
    $head = 'padding:6px 10px;text-align:left;font-weight:600;opacity:.65;';
@endphp

@if ($movements->isEmpty())
    <p style="font-size:14px;opacity:.7;margin:0;">No stock movements yet.</p>
@else
    <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr>
                    <th style="{{ $head }}">When</th>
                    <th style="{{ $head }}text-align:right;">Change</th>
                    <th style="{{ $head }}text-align:right;">After</th>
                    <th style="{{ $head }}">Note</th>
                    <th style="{{ $head }}">By</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($movements as $movement)
                    <tr>
                        <td style="{{ $cell }}white-space:nowrap;">{{ $movement->created_at->format('d M Y, H:i') }}</td>
                        <td style="{{ $cell }}text-align:right;white-space:nowrap;font-weight:600;color:{{ $movement->change >= 0 ? 'var(--success-600)' : 'var(--danger-600)' }};">
                            {{ $movement->change >= 0 ? '+' : '−' }}{{ StockItem::formatQuantity(abs($movement->change), $item->unit) }}
                        </td>
                        <td style="{{ $cell }}text-align:right;white-space:nowrap;">{{ StockItem::formatQuantity($movement->quantity_after, $item->unit) }}</td>
                        <td style="{{ $cell }}">{{ $movement->note ?? '—' }}</td>
                        <td style="{{ $cell }}white-space:nowrap;">{{ $movement->user?->name ?? 'System' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
