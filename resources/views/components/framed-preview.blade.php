{{-- Framed picture preview. Pass the array returned by App\Support\FramedPreview. --}}
@props(['preview', 'caption' => null])

<div style="display:inline-flex;flex-direction:column;align-items:center;gap:6px;max-width:100%;">
    <div style="{{ $preview['wallStyle'] }}">
        <span style="{{ $preview['frameStyle'] }}">
            <span style="{{ $preview['matStyle'] }}">
                <span style="{{ $preview['imageStyle'] }}"></span>
            </span>
        </span>
    </div>
    @if ($caption)
        <span style="font-size:12px;opacity:.7;text-align:center;">{{ $caption }}</span>
    @endif
</div>
