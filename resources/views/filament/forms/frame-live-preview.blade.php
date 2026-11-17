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
<div style="display:flex;justify-content:center;">
    <x-framed-preview :preview="$preview" caption="Sample 50 × 70 cm picture, drawn to scale" />
</div>
