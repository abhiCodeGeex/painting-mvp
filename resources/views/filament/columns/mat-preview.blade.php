@php
    /** @var \App\Models\Mat $record */
    $record = $getRecord();
    $preview = \App\Support\FramedPreview::make(widthMm: 300, heightMm: 400, mat: $record, frameColour: '#2b2b2b', frameWidthMm: 15, maxPx: 56);
@endphp
<div style="padding:4px 0;">
    <x-framed-preview :preview="$preview" />
</div>
