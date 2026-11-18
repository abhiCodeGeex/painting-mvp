@php
    /** @var \App\Models\StandardSize $record */
    $record = $getRecord();
    // All sizes drawn on the same scale (largest seeded size ≈ 1800 mm → 72 px) so they compare.
    $scale = 72 / 1800;
    $w = max(8, round($record->width_mm * $scale));
    $h = max(8, round($record->height_mm * $scale));
@endphp
<div style="display:flex;align-items:flex-end;height:76px;padding:2px 0;">
    <span title="{{ $record->label }}"
          style="display:block;width:{{ $w }}px;height:{{ $h }}px;border:2px solid #555;border-radius:2px;background:{{ \App\Support\FramedPreview::SAMPLE_IMAGE_GRADIENT }};"></span>
</div>
