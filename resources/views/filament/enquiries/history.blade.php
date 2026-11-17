@php
    use App\Enums\EnquiryStatus;

    /** @var \App\Models\Enquiry $enquiry */
    $enquiry = $getRecord();
    $history = $enquiry->statusHistory()->with('user')->get();
@endphp

<ol style="display:flex;flex-direction:column;gap:8px;font-size:13px;list-style:none;padding:0;margin:0;">
    <li style="opacity:.7;">Created {{ $enquiry->created_at->format('d M Y, H:i') }}</li>
    @foreach ($history as $entry)
        <li>
            <strong>{{ EnquiryStatus::tryFrom($entry->to)?->getLabel() ?? $entry->to }}</strong>
            <span style="opacity:.7;">· {{ $entry->created_at->format('d M Y, H:i') }} · {{ $entry->user?->name ?? 'System' }}</span>
            @if ($entry->note)
                <div style="opacity:.7;">{{ $entry->note }}</div>
            @endif
        </li>
    @endforeach
</ol>
