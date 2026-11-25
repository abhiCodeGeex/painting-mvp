<?php

namespace App\Http\Controllers\Portal;

use App\Enums\LineSource;
use App\Enums\OwnArtworkService;
use App\Http\Requests\Portal\EnquiryLineRequest;
use App\Models\Enquiry;
use App\Models\EnquiryLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Add / edit / duplicate / remove artwork lines on a draft enquiry.
 */
class EnquiryLineController extends PortalController
{
    public function store(EnquiryLineRequest $request, Enquiry $enquiry): RedirectResponse
    {
        DB::transaction(function () use ($request, $enquiry): void {
            $line = $enquiry->lines()->create([
                ...$this->attributes($request),
                'position' => (int) $enquiry->lines()->max('position') + 1,
            ]);
            $this->syncRelations($request, $line);
        });

        $enquiry->touch();

        return back()->with('success', 'Artwork added.');
    }

    public function update(EnquiryLineRequest $request, Enquiry $enquiry, EnquiryLine $line): RedirectResponse
    {
        abort_unless($line->enquiry_id === $enquiry->id, 404);

        DB::transaction(function () use ($request, $line): void {
            $line->update($this->attributes($request));
            $this->syncRelations($request, $line);
        });

        $enquiry->touch();

        return back()->with('success', 'Artwork updated.');
    }

    public function duplicate(Enquiry $enquiry, EnquiryLine $line): RedirectResponse
    {
        $this->authorize('update', $enquiry);
        abort_unless($line->enquiry_id === $enquiry->id, 404);

        DB::transaction(function () use ($enquiry, $line): void {
            $copy = $line->replicate(['created_at', 'updated_at']);
            $copy->position = (int) $enquiry->lines()->max('position') + 1;
            $copy->save();
            $copy->finishings()->sync($line->finishings()->pluck('finishings.id'));
            foreach (['references', 'artwork_photo', 'hi_res'] as $collection) {
                foreach ($line->getMedia($collection) as $media) {
                    $media->copy($copy, $collection, $media->disk);
                }
            }
        });

        return back()->with('success', 'Artwork duplicated.');
    }

    public function destroy(Enquiry $enquiry, EnquiryLine $line): RedirectResponse
    {
        $this->authorize('update', $enquiry);
        abort_unless($line->enquiry_id === $enquiry->id, 404);

        $line->delete();

        return back()->with('success', 'Artwork removed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(EnquiryLineRequest $request): array
    {
        $data = $request->validated();
        $source = LineSource::from($data['source_type']);

        return [
            'source_type' => $source,
            'artwork_id' => $source === LineSource::Catalog ? $data['artwork_id'] : null,
            'title' => $source === LineSource::Catalog ? null : ($data['title'] ?? null),
            'custom_brief' => $source === LineSource::CustomCreation ? [
                'description' => $data['brief']['description'] ?? null,
                'style' => $data['brief']['style'] ?? null,
                'mood' => array_values($data['brief']['mood'] ?? []),
                'palette' => array_values($data['brief']['palette'] ?? []),
                'usage_rights' => $data['brief']['usage_rights'] ?? null,
            ] : null,
            'own_artwork' => $source === LineSource::OwnArtwork ? [
                'service' => $data['own']['service'] ?? null,
                'condition_notes' => $data['own']['condition_notes'] ?? null,
            ] : null,
            'rights_confirmed_at' => $source === LineSource::OwnArtwork && $request->boolean('rights_confirmed') ? now() : null,
            'unit' => $data['unit'],
            'size_reference' => $data['size_reference'],
            'width_mm' => $data['width_mm'],
            'height_mm' => $data['height_mm'],
            'quantity' => $data['quantity'],
            'image_rotation' => (int) ($data['image_rotation'] ?? 0),
            'quality_tier_id' => $data['quality_tier_id'] ?? null,
            'medium_id' => $data['medium_id'] ?? null,
            'substrate_id' => $data['substrate_id'] ?? null,
            'edge_style' => $data['edge_style'] ?? null,
            'frame_id' => $data['frame_id'] ?? null,
            'frame_advice_requested' => (bool) ($data['frame_advice_requested'] ?? false),
            'mat_id' => $data['mat_id'] ?? null,
            'mat_border_mm' => ! empty($data['mat_id']) ? ($data['mat_border_mm'] ?? null) : null,
            'glazing_id' => $data['glazing_id'] ?? null,
            'placement' => $data['placement'] ?? null,
            'wall_width_mm' => $data['wall_width_mm'] ?? null,
            'wall_height_mm' => $data['wall_height_mm'] ?? null,
            'wall_colour' => $data['wall_colour'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function syncRelations(EnquiryLineRequest $request, EnquiryLine $line): void
    {
        $line->finishings()->sync($request->input('finishing_ids', []));

        foreach ($request->input('remove_media', []) as $mediaId) {
            $line->media()->whereKey($mediaId)->first()?->delete();
        }

        $source = $line->source_type;

        // Files that no longer apply to the chosen source are removed.
        if ($source !== LineSource::CustomCreation) {
            $line->clearMediaCollection('references');
        }
        if ($source !== LineSource::OwnArtwork) {
            $line->clearMediaCollection('artwork_photo');
            $line->clearMediaCollection('hi_res');
        }

        // New files are kept only where they belong to the chosen source.
        if ($source === LineSource::CustomCreation) {
            foreach ($request->file('references', []) as $file) {
                $line->addMedia($file)->toMediaCollection('references');
            }
        }
        if ($source === LineSource::OwnArtwork) {
            if ($request->hasFile('artwork_photo')) {
                $line->addMediaFromRequest('artwork_photo')->toMediaCollection('artwork_photo');
            }
            $reproduce = ($line->own_artwork['service'] ?? null) === OwnArtworkService::ReproduceAndFrame->value;
            if (! $reproduce) {
                $line->clearMediaCollection('hi_res');
            } elseif ($request->hasFile('hi_res')) {
                $line->addMediaFromRequest('hi_res')->toMediaCollection('hi_res');
            }
        }
    }
}
