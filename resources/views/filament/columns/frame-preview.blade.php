@php
    /** @var \App\Models\Frame $record */
    $record = $getRecord();
    $preview = \App\Support\FramedPreview::make(widthMm: 300, heightMm: 400, frame: $record, maxPx: 56);
@endphp
<div style="padding:4px 0;">
    <x-framed-preview :preview="$preview" />
</div>
