@php
    use App\Filament\Resources\Sales\Quotes\QuoteResource;
    use App\Support\Money;
    /** @var \App\Models\Enquiry $enquiry */
    $enquiry = $getRecord();
    $estimate = $enquiry->estimate_snapshot;
@endphp
<div class="space-y-3 text-sm">
    @forelse($enquiry->quotes as $quote)
        <a href="{{ QuoteResource::getUrl('edit', ['record' => $quote]) }}"
           class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2 hover:bg-gray-50 dark:border-white/10 dark:hover:bg-white/5">
            <span>
                <span class="font-medium">{{ $quote->displayNumber() }}</span>
                <x-filament::badge :color="$quote->status->getColor()" class="ms-1 inline-flex">{{ $quote->status->getLabel() }}</x-filament::badge>
            </span>
            <span class="font-medium">{{ Money::format($quote->grand_total) }}</span>
        </a>
    @empty
        <p class="text-gray-500">No quote yet. Use “Create quote” above.</p>
    @endforelse

    @if($estimate && ($estimate['mode'] ?? null) !== 'hidden')
        <p class="text-xs text-gray-500">
            Customer saw an estimate of
            <strong>{{ $estimate['is_range'] ? Money::format($estimate['total_low']).' – '.Money::format($estimate['total_high']) : Money::format($estimate['total_low']) }}</strong>
            incl. GST when submitting.
        </p>
    @endif
</div>
