{{-- Live preview in the Frame form: redrawn whenever colour, width or finish change. --}}
@php
    $preview = \App\Support\FramedPreview::make(
        widthMm: 500,
        heightMm: 700,
        wallColour: '#EDE8E1',
        maxPx: 200,
        frameColour: $colour ?: '#888888',
        frameWidthMm: (int) ($width ?: 20),
        finish: $finish,
    );
@endphp
@if (! empty($photo))
    <figure style="margin:0 0 14px;">
        <img src="{{ $photo }}" alt="{{ $name }} corner sample" loading="lazy"
             style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.12);">
        <figcaption style="font-size:12px;opacity:.65;margin-top:6px;text-align:center;">Corner sample photo</figcaption>
    </figure>
@endif
<div style="display:flex;justify-content:center;">
    <x-framed-preview :preview="$preview" caption="Sample 50 × 70 cm picture, drawn to scale" />
</div>
