{{-- Moulding strip built from the frame photo (App\Support\FrameStrip). Sits right under the 'Frame photo' upload. --}}
@if ($error)
    <div role="alert"
         style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border-radius:8px;
                border:1px solid rgba(220,38,38,.45);background:rgba(220,38,38,.1);color:rgb(185,28,28);font-size:13px;line-height:1.4;">
        <x-filament::icon icon="heroicon-o-exclamation-triangle" style="width:18px;height:18px;flex:none;margin-top:1px;" />
        <div>
            <strong>Couldn't build the frame from this photo:</strong> {{ $error }}
            <div style="opacity:.8;margin-top:4px;">Try another photo, set what the photo shows, then save or use “Rebuild moulding strip”.</div>
        </div>
    </div>
@elseif ($strip)
    <div style="padding:10px 12px;border-radius:8px;border:1px solid rgba(22,163,74,.4);background:rgba(22,163,74,.08);font-size:13px;">
        <div style="display:flex;gap:8px;align-items:center;color:rgb(21,128,61);font-weight:600;margin-bottom:8px;">
            <x-filament::icon icon="heroicon-o-check-circle" style="width:18px;height:18px;flex:none;" />
            Frame built from photo
        </div>
        <div style="height:48px;border-radius:6px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.15);
                    background:url('{{ $strip->getUrl() }}') left top / auto 100% repeat-x;"></div>
        <div style="font-size:12px;opacity:.7;margin-top:6px;">
            Outer edge on top, picture edge at the bottom.
            Read as: {{ \App\Enums\FramePhotoType::tryFrom((string) $strip->getCustomProperty('detected_type'))?->getLabel() ?? '—' }}
            · {{ $strip->getCustomProperty('width') }} × {{ $strip->getCustomProperty('height') }} px
        </div>
    </div>
@elseif ($hasPhoto)
    {{-- Queued build running: poll until the strip (or an error) appears. --}}
    <p wire:poll.3s="refreshStrip" style="font-size:13px;opacity:.7;margin:0;">Building the strip… this updates by itself in a few seconds.</p>
@else
    <p style="font-size:13px;opacity:.7;margin:0;">No photo yet — the portal draws this frame from its colour and shape.</p>
@endif
