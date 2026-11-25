@php
    use App\Enums\LineSource;
    use App\Support\FramedPreview;
    use App\Support\MediaUrl;

    /** @var \App\Models\Enquiry $enquiry */
    $enquiry = $getRecord();
    $lines = $enquiry->lines()->with(['artwork.media', 'artwork.artist', 'qualityTier', 'medium', 'substrate', 'frame', 'mat', 'glazing', 'finishings', 'media'])->get();

    $row = fn (string $label, ?string $value) => $value === null || $value === '' ? '' :
        '<div style="display:flex;gap:8px;padding:3px 0;border-bottom:1px dashed rgba(128,128,128,.2);"><span style="min-width:120px;opacity:.65;">'.e($label).'</span><span style="font-weight:500;">'.e($value).'</span></div>';
@endphp

@if ($lines->isEmpty())
    <p style="opacity:.6;">No artworks added yet.</p>
@endif

<div style="display:flex;flex-direction:column;gap:20px;">
    @foreach ($lines as $i => $line)
        @php
            $brief = $line->custom_brief ?? [];
            $own = $line->own_artwork ?? [];
            $files = $line->getMedia('references')->concat($line->getMedia('artwork_photo'))->concat($line->getMedia('hi_res'));
        @endphp
        <div style="display:flex;flex-wrap:wrap;gap:20px;padding:16px;border:1px solid rgba(128,128,128,.25);border-radius:12px;">
            <div style="flex:0 0 auto;">
                <x-framed-preview :preview="FramedPreview::forLine($line, 200)" caption="Preview on wall (to scale)" />
                @if ($fullUrl = $line->previewImageUrl(null))
                    {{-- The original artwork image, full resolution, in a new tab (zoom in with the browser). --}}
                    <a href="{{ $fullUrl }}" target="_blank" rel="noopener"
                       style="display:inline-flex;align-items:center;gap:6px;margin-top:8px;font-size:13px;font-weight:600;padding:6px 12px;border-radius:8px;border:1px solid rgba(128,128,128,.35);text-decoration:none;">
                        &#x2922; View artwork full size
                    </a>
                @endif
            </div>

            <div style="flex:1 1 320px;min-width:260px;font-size:14px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <span style="font-size:12px;padding:2px 8px;border-radius:999px;background:rgba(128,128,128,.15);">#{{ $i + 1 }} · {{ $line->source_type->getLabel() }}</span>
                    <strong style="font-size:16px;">{{ $line->displayTitle() }}</strong>
                    @if ($line->sidesSwapped())
                        <span style="font-size:12px;padding:2px 8px;border-radius:999px;background:rgba(245,158,11,.18);color:#b45309;font-weight:600;">Sides swapped</span>
                    @endif
                </div>

                {!! $row('Artwork', $line->source_type === LineSource::Catalog ? trim(($line->artwork?->code ?? '').' — '.($line->artwork?->artist?->name ?? 'Unknown artist'), ' —') : null) !!}
                {!! $row('Size', $line->printSizeLabel().' ('.$line->size_reference->getLabel().')') !!}
                {!! $row('Hanging', $line->rotationLabel()) !!}
                {!! $row('Quantity', (string) $line->quantity) !!}
                {!! $row('Quality', $line->qualityTier?->name) !!}
                {!! $row('Medium', $line->medium?->name) !!}
                {!! $row('Substrate', $line->substrate?->name) !!}
                {!! $row('Edges', $line->edge_style?->getLabel()) !!}
                @php
                    // Every frame layer (innermost first), with its split moulding if any.
                    $layers = $line->frameLayers();
                    $layerFrames = \App\Models\Frame::withTrashed()->whereIn('id', \App\Domain\Pricing\FrameLayers::frameIds($layers))->get()->keyBy('id');
                    $frameName = fn (int $id) => ($f = $layerFrames->get($id)) ? $f->name.' ('.$f->code.')' : 'Frame #'.$id;
                @endphp
                @if (count($layers) > 1 || ($layers[0]['split'] ?? null))
                    @foreach ($layers as $n => $layer)
                        {!! $row($n === 0 ? 'Frame 1 (inner)' : 'Frame '.($n + 1).' (around '.$n.')',
                            $frameName($layer['frame_id']).($layer['split']
                                ? ' · split '.\App\Domain\Pricing\FrameLayers::modeLabel($layer['split']['mode']).': '
                                    .($layer['split']['mode'] === 'halves' ? 'bottom half ' : 'sides ').$frameName($layer['split']['frame_id'])
                                : '')) !!}
                    @endforeach
                @else
                    {!! $row('Frame', $line->frame ? $line->frame->name.' ('.$line->frame->code.')' : ($line->frame_advice_requested ? 'Customer wants our advice' : 'No frame')) !!}
                @endif
                {!! $row('Mat', $line->mat ? $line->mat->name.' — '.($line->mat_border_mm ?? $line->mat->default_border_mm).' mm border' : null) !!}
                {!! $row('Glazing', $line->glazing?->name) !!}
                {!! $row('Finishing', $line->finishings->pluck('name')->implode(', ')) !!}
                {!! $row('Placement', $line->placement) !!}
                {!! $row('Wall', $line->wall_width_mm ? round($line->wall_width_mm / 10).' × '.round(($line->wall_height_mm ?? 0) / 10).' cm' : null) !!}

                @if ($line->source_type === LineSource::CustomCreation)
                    {!! $row('Brief', $brief['description'] ?? null) !!}
                    {!! $row('Style', $brief['style'] ?? null) !!}
                    {!! $row('Mood', implode(', ', $brief['mood'] ?? [])) !!}
                    {!! $row('Usage', isset($brief['usage_rights']) ? \App\Enums\UsageRights::tryFrom($brief['usage_rights'])?->getLabel() : null) !!}
                    @if (! empty($brief['palette']))
                        <div style="display:flex;gap:8px;padding:6px 0;align-items:center;">
                            <span style="min-width:120px;opacity:.65;">Colours</span>
                            @foreach ($brief['palette'] as $hex)
                                <span title="{{ $hex }}" style="width:22px;height:22px;border-radius:6px;background:{{ $hex }};border:1px solid rgba(0,0,0,.2);"></span>
                            @endforeach
                        </div>
                    @endif
                @endif

                @if ($line->source_type === LineSource::OwnArtwork)
                    {!! $row('Service', isset($own['service']) ? \App\Enums\OwnArtworkService::tryFrom($own['service'])?->getLabel() : null) !!}
                    {!! $row('Condition', $own['condition_notes'] ?? null) !!}
                    {!! $row('Rights', $line->rights_confirmed_at ? 'Customer confirmed they own the rights' : null) !!}
                @endif

                {!! $row('Notes', $line->notes) !!}

                @if ($files->isNotEmpty())
                    <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;">
                        @foreach ($files as $file)
                            <a href="{{ MediaUrl::for($file) }}" target="_blank"
                               style="display:flex;flex-direction:column;align-items:center;gap:4px;width:92px;font-size:11px;text-decoration:none;color:inherit;">
                                @if (MediaUrl::isImage($file))
                                    <img src="{{ MediaUrl::for($file, 'thumb') }}" alt="" style="width:88px;height:88px;object-fit:cover;border-radius:8px;border:1px solid rgba(128,128,128,.3);">
                                @else
                                    <span style="width:88px;height:88px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid rgba(128,128,128,.3);font-weight:600;">{{ strtoupper(pathinfo($file->file_name, PATHINFO_EXTENSION)) }}</span>
                                @endif
                                <span style="max-width:92px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $file->file_name }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endforeach
</div>
