{{-- Live preview in the Frame form: redrawn whenever colour, width or finish change.
     With a built moulding strip (top = outer edge, bottom = inner edge, tileable along X) the
     sample is wrapped in four mitred rails cut from the strip; otherwise it is drawn from the colour. --}}
@php
    $frameWidthMm = max(1, (int) ($width ?: 20));
    $strip = $strip ?? null;

    if ($strip) {
        $picW = 500;
        $picH = 700;
        $scale = 200 / max($picW + 2 * $frameWidthMm, $picH + 2 * $frameWidthMm);
        $f = max(2, round($frameWidthMm * $scale, 1));
        $iw = round($picW * $scale, 1);
        $ih = round($picH * $scale, 1);
        $W = $iw + 2 * $f;
        $H = $ih + 2 * $f;
        $bg = "background:url('".e($strip)."') left top / auto {$f}px repeat-x;";
        // Trapezoid: full length on the outer (top) edge, inset by the face width at the inner edge => 45° mitres.
        $clip = fn (float $len): string => "clip-path:polygon(0 0,{$len}px 0,".($len - $f)."px {$f}px,{$f}px {$f}px);";
        $rail = 'position:absolute;height:'.$f.'px;transform-origin:center;'.$bg;
        $rails = [
            // top: outer edge up
            "{$rail}left:0;top:0;width:{$W}px;".$clip($W),
            // bottom: rotated 180° so the outer edge faces down
            "{$rail}left:0;top:".($H - $f)."px;width:{$W}px;transform:rotate(180deg);".$clip($W),
            // right: rotated 90° so the outer edge faces right
            "{$rail}left:".($W - $f / 2 - $H / 2).'px;top:'.($H / 2 - $f / 2)."px;width:{$H}px;transform:rotate(90deg);".$clip($H),
            // left: rotated -90° so the outer edge faces left
            "{$rail}left:".($f / 2 - $H / 2).'px;top:'.($H / 2 - $f / 2)."px;width:{$H}px;transform:rotate(-90deg);".$clip($H),
        ];
    } else {
        $preview = \App\Support\FramedPreview::make(
            widthMm: 500,
            heightMm: 700,
            wallColour: '#EDE8E1',
            maxPx: 200,
            frameColour: $colour ?: '#888888',
            frameWidthMm: $frameWidthMm,
            finish: $finish,
        );
    }
@endphp
@if (! empty($photo))
    <figure style="margin:0 0 14px;">
        <img src="{{ $photo }}" alt="{{ $name }} corner sample" loading="lazy"
             style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.12);">
        <figcaption style="font-size:12px;opacity:.65;margin-top:6px;text-align:center;">Corner sample photo</figcaption>
    </figure>
@endif
<div style="display:flex;justify-content:center;">
    @if ($strip)
        <div style="display:inline-flex;flex-direction:column;align-items:center;gap:6px;max-width:100%;">
            <div style="display:flex;align-items:center;justify-content:center;padding:24px;border-radius:8px;background:#EDE8E1;">
                <div data-frame-strip-preview
                     style="position:relative;width:{{ $W }}px;height:{{ $H }}px;flex:none;filter:drop-shadow(0 6px 10px rgba(0,0,0,.3));">
                    <span style="position:absolute;left:{{ $f }}px;top:{{ $f }}px;width:{{ $iw }}px;height:{{ $ih }}px;background:{{ \App\Support\FramedPreview::SAMPLE_IMAGE_GRADIENT }};"></span>
                    @foreach ($rails as $style)
                        <span style="{{ $style }}"></span>
                    @endforeach
                </div>
            </div>
            <span style="font-size:12px;opacity:.7;text-align:center;">Sample 50 × 70 cm picture, drawn to scale (from the frame photo)</span>
        </div>
    @else
        <x-framed-preview :preview="$preview" caption="Sample 50 × 70 cm picture, drawn to scale" />
    @endif
</div>
@if ($strip)
    <figure style="margin:14px 0 0;">
        <img src="{{ $strip }}" alt="{{ $name }} moulding strip" loading="lazy"
             style="display:block;width:100%;height:auto;border-radius:6px;box-shadow:0 1px 3px rgba(0,0,0,.12);">
        <figcaption style="font-size:12px;opacity:.65;margin-top:6px;text-align:center;">Moulding strip (outer edge on top)</figcaption>
    </figure>
@endif
