@php
    use App\Support\Money;
    /** @var \App\Models\Quote $quote */
    $customer = $quote->customer_snapshot ?? [];
    $delivery = $quote->delivery_snapshot ?? [];
    $mandatory = $quote->lines->where('is_optional', false);
    $optional = $quote->lines->where('is_optional', true);
    // Work after the artwork and labour are already in the unit price; listing them tells the
    // customer what that price covers without adding rows to the table.
    $includes = function (\App\Models\QuoteLine $line): ?string {
        $parts = collect($line->work_items ?? [])
            ->filter(fn ($i) => (int) ($i['amount'] ?? 0) > 0 && filled($i['name'] ?? null))
            ->map(fn ($i) => $i['name'].' '.Money::format((int) $i['amount']));
        if ((int) $line->labour_amount > 0) {
            $parts->push('Labour '.Money::format((int) $line->labour_amount));
        }

        return $parts->isEmpty() ? null : 'Includes: '.$parts->implode(' · ').($line->quantity > 1 ? ' per piece' : '');
    };
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Quotation {{ $quote->displayNumber() }}</title>
    <style>
        @page { margin: 18mm 14mm; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", Arial, sans-serif; font-size: 10px; color: #1f2937; margin: 0; }
        h1 { font-size: 20px; margin: 0 0 2px; letter-spacing: .5px; }
        .muted { color: #6b7280; }
        .row { width: 100%; }
        .row td { vertical-align: top; }
        table { width: 100%; border-collapse: collapse; }
        .box { border: 1px solid #e5e7eb; border-radius: 4px; padding: 8px 10px; }
        .lines th { background: #f3f4f6; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .4px; padding: 6px; border-bottom: 1px solid #d1d5db; }
        .lines td { padding: 7px 6px; border-bottom: 1px solid #eef0f3; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .totals td { padding: 3px 6px; }
        .totals .grand td { font-size: 13px; font-weight: bold; border-top: 2px solid #111827; padding-top: 6px; }
        .section { margin-top: 14px; }
        .section h3 { font-size: 11px; margin: 0 0 4px; text-transform: uppercase; letter-spacing: .4px; }
        .tag { display: inline-block; padding: 1px 5px; border-radius: 3px; background: #fef3c7; color: #92400e; font-size: 8px; }
        .small { font-size: 8.5px; }
    </style>
</head>
<body>
<table class="row">
    <tr>
        <td style="width:60%">
            <h1>{{ $company->name }}</h1>
            <div class="muted">
                {{ collect([$company->address_line1, $company->address_line2, $company->city, $company->pin_code])->filter()->implode(', ') }}<br>
                @if($company->gstin) GSTIN: {{ $company->gstin }} · @endif
                {{ collect([$company->phone, $company->email])->filter()->implode(' · ') }}
            </div>
        </td>
        <td style="width:40%; text-align:right">
            <div style="font-size:16px; font-weight:bold">QUOTATION</div>
            <div><strong>{{ $quote->number }}</strong> · Version {{ $quote->version }}</div>
            <div class="muted">Date: {{ $quote->issue_date?->format('d M Y') ?? now()->format('d M Y') }}</div>
            <div class="muted">Valid until: {{ $quote->valid_until?->format('d M Y') ?? '—' }}</div>
            <div class="muted">Enquiry: {{ $quote->enquiry->reference }}</div>
        </td>
    </tr>
</table>

<table class="row section">
    <tr>
        <td style="width:50%; padding-right:6px">
            <div class="box">
                <div class="muted small">QUOTATION FOR</div>
                <strong>{{ $customer['name'] ?? $quote->enquiry->customer->displayName() }}</strong><br>
                @if(!empty($customer['contact']) && ($customer['contact'] !== ($customer['name'] ?? null))) {{ $customer['contact'] }}<br>@endif
                {{ collect([$customer['email'] ?? null, $customer['phone'] ?? null])->filter()->implode(' · ') }}
                @if(!empty($customer['gstin']))<br>GSTIN: {{ $customer['gstin'] }}@endif
            </div>
        </td>
        <td style="width:50%; padding-left:6px">
            <div class="box">
                <div class="muted small">DELIVER TO · PROJECT</div>
                {{ $delivery['one_line'] ?? '—' }}<br>
                <span class="muted">Place of supply: {{ $delivery['state'] ?? '—' }} {{ $quote->place_of_supply ? '('.$quote->place_of_supply.')' : '' }}</span><br>
                Project: <strong>{{ $quote->enquiry->project_name }}</strong>
            </div>
        </td>
    </tr>
</table>

<table class="lines section">
    <thead>
    <tr>
        <th style="width:4%">#</th>
        <th>Artwork &amp; specification</th>
        <th class="num" style="width:7%">Qty</th>
        <th class="num" style="width:15%">Unit price</th>
        <th class="num" style="width:15%">Amount</th>
    </tr>
    </thead>
    <tbody>
    @foreach($mandatory as $i => $line)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td><strong>{{ $line->title }}</strong><br><span class="muted">{{ $line->spec }}</span>@if($inc = $includes($line))<br><span class="muted small">{{ $inc }}</span>@endif</td>
            <td class="num">{{ $line->quantity }}</td>
            <td class="num">{{ Money::format($line->unit_price) }}</td>
            <td class="num">
                {{ Money::format($line->line_total) }}
                @if($line->discount_amount > 0)<br><span class="muted small">incl. −{{ Money::format($line->discount_amount) }}</span>@endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>

@if($optional->isNotEmpty())
    <div class="section">
        <h3>Optional items</h3>
        <table class="lines">
            @foreach($optional as $line)
                <tr>
                    <td style="width:4%"><span class="tag">OPT</span></td>
                    <td><strong>{{ $line->title }}</strong><br><span class="muted">{{ $line->spec }}</span>@if($inc = $includes($line))<br><span class="muted small">{{ $inc }}</span>@endif</td>
                    <td class="num" style="width:7%">{{ $line->quantity }}</td>
                    <td class="num" style="width:15%">{{ Money::format($line->unit_price) }}</td>
                    <td class="num" style="width:15%">{{ Money::format($line->line_total) }}{{ $line->is_selected ? '' : ' (not included)' }}</td>
                </tr>
            @endforeach
        </table>
    </div>
@endif

<table class="row section">
    <tr>
        <td style="width:55%; padding-right:10px">
            @if($quote->extras->isNotEmpty())
                <h3 style="font-size:11px; margin:0 0 4px">INCLUSIONS &amp; EXTRAS</h3>
                <table class="small">
                    @foreach($quote->extras as $extra)
                        <tr>
                            <td>{{ $extra->name }}</td>
                            <td class="num">{{ $extra->inclusion === 'charged' ? Money::format($extra->amount) : $extra->inclusionLabel() }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </td>
        <td style="width:45%">
            <table class="totals">
                <tr><td>Items</td><td class="num">{{ Money::format($quote->items_total) }}</td></tr>
                @if($quote->extras_total > 0)<tr><td>Extras</td><td class="num">{{ Money::format($quote->extras_total) }}</td></tr>@endif
                @if($quote->discount_amount > 0)<tr><td>Discount{{ $quote->discount_percent > 0 ? ' ('.rtrim(rtrim($quote->discount_percent, '0'), '.').'%)' : '' }}</td><td class="num">−{{ Money::format($quote->discount_amount) }}</td></tr>@endif
                <tr><td>Taxable value</td><td class="num">{{ Money::format($quote->taxable) }}</td></tr>
                @php $half = rtrim(rtrim(number_format((float) $quote->tax_rate / 2, 2), '0'), '.'); $full = rtrim(rtrim(number_format((float) $quote->tax_rate, 2), '0'), '.'); @endphp
                @if($quote->intra_state)
                    <tr><td>CGST {{ $half }}%</td><td class="num">{{ Money::format($quote->cgst) }}</td></tr>
                    <tr><td>SGST {{ $half }}%</td><td class="num">{{ Money::format($quote->sgst) }}</td></tr>
                @else
                    <tr><td>IGST {{ $full }}%</td><td class="num">{{ Money::format($quote->igst) }}</td></tr>
                @endif
                <tr class="grand"><td>Total (INR)</td><td class="num">{{ Money::format($quote->grand_total) }}</td></tr>
            </table>
        </td>
    </tr>
</table>

@if($quote->status === \App\Enums\QuoteStatus::Approved && $quote->responded_at)
    <div class="section box" style="border:2px solid #059669; color:#065f46">
        <strong>APPROVED</strong> on {{ $quote->responded_at->timezone(config('app.timezone'))->format('d M Y, H:i') }}
        by {{ $quote->response['name'] ?? '' }}@if(!empty($quote->response['role'])), {{ $quote->response['role'] }}@endif
        @if(!empty($quote->response['po_number'])) · PO {{ $quote->response['po_number'] }}@endif
        · Total {{ Money::format($quote->grand_total) }}
    </div>
@endif

@if($quote->note_to_customer)
    <div class="section box">{!! nl2br(e($quote->note_to_customer)) !!}</div>
@endif

<table class="row section">
    <tr>
        <td style="width:50%; padding-right:8px">
            <h3 style="font-size:11px; margin:0 0 4px">LEAD TIME</h3>
            <div>{{ $quote->lead_time ?: '—' }}</div>
            <h3 style="font-size:11px; margin:10px 0 4px">PAYMENT TERMS</h3>
            <div>{!! nl2br(e($quote->payment_terms)) !!}</div>
            @if($company->bank_account_number)
                <div class="small muted" style="margin-top:6px">
                    Bank: {{ $company->bank_name }} · A/c {{ $company->bank_account_name }} · {{ $company->bank_account_number }} · IFSC {{ $company->bank_ifsc }}
                </div>
            @endif
        </td>
        <td style="width:50%">
            <h3 style="font-size:11px; margin:0 0 4px">TERMS &amp; CONDITIONS</h3>
            <div class="small">{!! nl2br(e($quote->terms)) !!}</div>
        </td>
    </tr>
</table>

<p class="small muted section">
    Visual previews are indicative; colours and textures may vary.
    @if($quote->preparer) Prepared by {{ $quote->preparer->name }} ({{ $quote->preparer->email }}). @endif
</p>
</body>
</html>
