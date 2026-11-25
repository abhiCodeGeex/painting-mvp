<?php

namespace App\Http\Requests\Portal;

use App\Domain\Enquiries\LineCompatibility;
use App\Domain\Pricing\FrameLayers;
use App\Enums\EdgeStyle;
use App\Enums\LengthUnit;
use App\Enums\LineSource;
use App\Enums\OwnArtworkService;
use App\Enums\SizeReference;
use App\Enums\UsageRights;
use App\Models\EnquiryLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One artwork line in the customer's enquiry (spec §6.2). Sizes arrive in mm.
 */
class EnquiryLineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('enquiry')->isEditableBy($this->user());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var EnquiryLine|null $existing */
        $existing = $this->route('line');
        $source = $this->input('source_type');
        $isCatalog = $source === LineSource::Catalog->value;
        $isCustom = $source === LineSource::CustomCreation->value;
        $isOwn = $source === LineSource::OwnArtwork->value;
        $image = ['file', 'max:20480', 'mimes:jpg,jpeg,png,webp,heic,pdf'];

        return [
            'source_type' => ['required', Rule::enum(LineSource::class)],
            'artwork_id' => [Rule::requiredIf($isCatalog), 'nullable', Rule::exists('artworks', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'title' => ['nullable', 'string', 'max:255'],

            'brief.description' => [Rule::requiredIf($isCustom), 'nullable', 'string', 'max:5000'],
            'brief.style' => ['nullable', 'string', 'max:100'],
            'brief.mood' => ['nullable', 'array', 'max:10'],
            'brief.mood.*' => ['string', 'max:40'],
            'brief.palette' => ['nullable', 'array', 'max:6'],
            'brief.palette.*' => ['string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'brief.usage_rights' => [Rule::requiredIf($isCustom), 'nullable', Rule::enum(UsageRights::class)],
            'references' => ['nullable', 'array', 'max:10'],
            'references.*' => $image,
            'remove_media' => ['nullable', 'array'],
            'remove_media.*' => ['integer'],

            'own.service' => [Rule::requiredIf($isOwn), 'nullable', Rule::enum(OwnArtworkService::class)],
            'own.condition_notes' => ['nullable', 'string', 'max:2000'],
            'artwork_photo' => [Rule::requiredIf($isOwn && ! $existing?->hasMedia('artwork_photo')), 'nullable', ...$image],
            'hi_res' => ['nullable', 'file', 'max:204800', 'mimes:jpg,jpeg,png,tif,tiff,pdf,psd,ai'],
            // "accepted" always runs (even when empty), so only demand it for own artwork.
            'rights_confirmed' => ['accepted_if:source_type,own_artwork'],

            'unit' => ['required', Rule::enum(LengthUnit::class)],
            'size_reference' => ['required', Rule::enum(SizeReference::class)],
            'width_mm' => ['required', 'integer', 'min:50', 'max:6000'],
            'height_mm' => ['required', 'integer', 'min:50', 'max:6000'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'image_rotation' => ['nullable', 'integer', Rule::in(EnquiryLine::ROTATIONS)],

            'quality_tier_id' => ['nullable', Rule::exists('quality_tiers', 'id')->whereNull('deleted_at')],
            'medium_id' => ['nullable', Rule::exists('mediums', 'id')->whereNull('deleted_at')],
            'substrate_id' => ['nullable', Rule::exists('substrates', 'id')->whereNull('deleted_at')],
            'edge_style' => ['nullable', Rule::enum(EdgeStyle::class)],
            'frame_id' => ['nullable', Rule::exists('frames', 'id')->whereNull('deleted_at')],
            'frame_advice_requested' => ['boolean'],
            // Stacked frames, innermost first; any layer may be split between two mouldings.
            'frame_layers' => ['nullable', 'array', 'max:'.FrameLayers::MAX],
            'frame_layers.*.frame_id' => ['required', 'integer', $activeFrame = Rule::exists('frames', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'frame_layers.*.split' => ['nullable', 'array'],
            'frame_layers.*.split.frame_id' => ['required_with:frame_layers.*.split', 'integer', $activeFrame],
            'frame_layers.*.split.mode' => ['required_with:frame_layers.*.split', Rule::in(FrameLayers::MODES)],
            'mat_id' => ['nullable', Rule::exists('mats', 'id')->whereNull('deleted_at')],
            'mat_border_mm' => ['nullable', 'integer', 'min:10', 'max:300'],
            'glazing_id' => ['nullable', Rule::exists('glazings', 'id')->whereNull('deleted_at')],
            'finishing_ids' => ['nullable', 'array'],
            'finishing_ids.*' => [Rule::exists('finishings', 'id')->whereNull('deleted_at')],

            'placement' => ['nullable', 'string', 'max:255'],
            'wall_width_mm' => ['nullable', 'integer', 'min:100', 'max:30000'],
            'wall_height_mm' => ['nullable', 'integer', 'min:100', 'max:10000'],
            'wall_colour' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The editor posts multipart form data, so the frame layers arrive as JSON text. An empty split
     * (no second frame) means a plain layer.
     */
    protected function prepareForValidation(): void
    {
        $layers = $this->input('frame_layers');
        if (is_string($layers)) {
            $layers = $layers === '' ? null : json_decode($layers, true);
            $layers = is_array($layers) ? $layers : null;
        }
        if (is_array($layers)) {
            $layers = array_map(function ($layer) {
                if (is_array($layer) && array_key_exists('split', $layer)
                    && (! is_array($layer['split']) || empty($layer['split']['frame_id']))) {
                    $layer['split'] = null;
                }

                return $layer;
            }, array_values($layers));
        }
        $this->merge(['frame_layers' => $layers ?: null]);
        // frame_id is always the first (inner) layer's frame.
        if ($layers && is_array($layers[0] ?? null) && ! empty($layers[0]['frame_id'])) {
            $this->merge(['frame_id' => $layers[0]['frame_id']]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'frame_layers.max' => 'A picture can have at most '.FrameLayers::MAX.' frames.',
            'frame_layers.*.split.mode.in' => 'Choose how to split the frame: top & bottom / sides, or top half / bottom half.',
            'artwork_id.required' => 'Choose an artwork from the catalog.',
            'brief.description.required' => 'Describe the artwork you would like us to create.',
            'brief.usage_rights.required' => 'Tell us where the artwork will be displayed.',
            'artwork_photo.required' => 'Upload a photo of your artwork.',
            'rights_confirmed.accepted_if' => 'Please confirm you own the rights to this image.',
            'width_mm.min' => 'The minimum size is 5 cm.',
            'height_mm.min' => 'The minimum size is 5 cm.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $isCustom = $this->input('source_type') === LineSource::CustomCreation->value;
                /** @var EnquiryLine|null $existing */
                $existing = $this->route('line');
                $keptReferences = $existing
                    ? $existing->getMedia('references')->reject(fn ($m) => in_array($m->id, $this->input('remove_media', [])))->count()
                    : 0;
                if ($isCustom && $keptReferences + count($this->file('references', [])) === 0) {
                    $validator->errors()->add('references', 'Add at least one sketch or reference image.');
                }

                foreach (LineCompatibility::check($this->validated())->errors as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            },
        ];
    }
}
