{{-- Live preview in the Mat form. --}}
@php
    $mat = new \App\Models\Mat([
        'colour_hex' => $colour ?: '#FFFFFF',
        'core_colour_hex' => $core ?: '#FFFFFF',
        'default_border_mm' => (int) ($border ?: 50),
        'bottom_weight_mm' => (int) ($bottom ?: 0),
    ]);
    $preview = \App\Support\FramedPreview::make(
        widthMm: 400,
        heightMm: 500,
        mat: $mat,
        wallColour: '#EDE8E1',
        maxPx: 200,
        frameColour: '#2b2b2b',
        frameWidthMm: 20,
    );
@endphp
<div style="display:flex;justify-content:center;">
    <x-framed-preview :preview="$preview" caption="Sample 40 × 50 cm picture in a black frame" />
</div>
