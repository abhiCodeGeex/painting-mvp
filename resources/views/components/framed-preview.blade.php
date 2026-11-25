{{-- Framed picture preview. Pass the array returned by App\Support\FramedPreview. --}}
@props(['preview', 'caption' => null])

<div style="display:inline-flex;flex-direction:column;align-items:center;gap:6px;max-width:100%;">
    <div style="{{ $preview['wallStyle'] }}">
        {{-- Stacked frames: each outer layer wraps the one inside it. --}}
        @foreach ($preview['outerFrameStyles'] ?? [] as $outerStyle)<span style="{{ $outerStyle }}">@endforeach
        <span style="{{ $preview['frameStyle'] }}">
            <span style="{{ $preview['matStyle'] }}">
                <span style="{{ $preview['imageStyle'] }}">@if (! empty($preview['imageInnerStyle']))<span style="{{ $preview['imageInnerStyle'] }}"></span>@endif</span>
            </span>
        </span>
        @foreach ($preview['outerFrameStyles'] ?? [] as $outerStyle)</span>@endforeach
    </div>
    @if ($caption)
        <span style="font-size:12px;opacity:.7;text-align:center;">{{ $caption }}</span>
    @endif
</div>
