<?php

namespace App\Http\Presenters;

use App\Domain\Pricing\FrameLayers;
use App\Enums\BudgetRange;
use App\Enums\EdgeStyle;
use App\Enums\LengthUnit;
use App\Enums\OwnArtworkService;
use App\Enums\PreferredContact;
use App\Enums\ProjectType;
use App\Enums\SizeReference;
use App\Enums\UsageRights;
use App\Models\Artwork;
use App\Models\Category;
use App\Models\Enquiry;
use App\Models\EnquiryLine;
use App\Models\Finishing;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\Medium;
use App\Models\QualityTier;
use App\Models\StandardSize;
use App\Models\State;
use App\Models\Substrate;
use App\Settings\PricingSettings;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Collection;

/**
 * Active masters, shaped for the portal's enquiry editor and its live preview.
 */
class CatalogOptions
{
    private const ARTWORK_WITH = ['media', 'artist', 'categories.parent', 'sizePrices', 'mediums', 'substrates'];

    private const FRAME_WITH = ['media', 'substrates'];

    /**
     * With an enquiry, frames and artworks that staff deactivated after the customer chose them are
     * included too (flagged is_active: false): the line keeps them, so the editor must still be able
     * to draw and name them. Offering them for a new choice is the editor's call, using the flag.
     *
     * @return array<string, mixed>
     */
    public static function forEditor(?Enquiry $enquiry = null): array
    {
        $lines = $enquiry ? $enquiry->lines()->get(['id', 'artwork_id', 'frame_id', 'frame_layers']) : collect();
        $artworkIds = $lines->pluck('artwork_id')->filter()->unique()->values()->all();
        $frameIds = $lines->flatMap(fn (EnquiryLine $l) => [$l->frame_id, ...FrameLayers::frameIds($l->frameLayers())])
            ->filter()->unique()->values()->all();

        return [
            // The small gap the frame's rebate leaves around the mat/picture (each side): pricing counts it in
            // the outer size, so the previews do too.
            'rebate_allowance_mm' => (int) app(PricingSettings::class)->rebate_allowance_mm,
            'artworks' => self::withInactive(
                Artwork::active()->ordered()->with(self::ARTWORK_WITH)->get(),
                Artwork::query()->with(self::ARTWORK_WITH)->where('is_active', false)->whereIn('id', $artworkIds)->ordered()->get(),
            )->map(fn (Artwork $a) => self::artwork($a))->values()->all(),
            'categories' => Category::active()->whereNull('parent_id')->ordered()
                ->with(['children' => fn ($q) => $q->active()->ordered()])->get()
                ->flatMap(fn (Category $c) => [$c->name, ...$c->children->map(fn (Category $child) => "{$c->name} › {$child->name}")])
                ->values()->all(),
            'mediums' => Medium::active()->ordered()->with('substrates')->get()->map(fn (Medium $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'kind' => $m->kind->value,
                'substrate_ids' => $m->substrates->pluck('id')->all(),
                'description' => $m->description,
            ])->values()->all(),
            'substrates' => Substrate::active()->ordered()->get()->map(fn (Substrate $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'depth_mm' => $s->depth_mm,
                'edge_styles' => $s->edge_styles ?? [],
                'allows_glazing' => $s->allows_glazing,
                'allows_mat' => $s->allows_mat,
                'requires_frame' => $s->requires_frame,
                'max_width_mm' => $s->max_width_mm,
                'max_height_mm' => $s->max_height_mm,
            ])->values()->all(),
            'frames' => self::withInactive(
                Frame::active()->ordered()->with(self::FRAME_WITH)->get(),
                Frame::query()->with(self::FRAME_WITH)->where('is_active', false)->whereIn('id', $frameIds)->ordered()->get(),
            )->map(fn (Frame $f) => self::frame($f))->values()->all(),
            'mats' => Mat::active()->ordered()->get()->map(fn (Mat $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'colour' => $m->colour_hex,
                'core_colour' => $m->core_colour_hex,
                'default_border_mm' => $m->default_border_mm,
                'bottom_weight_mm' => $m->bottom_weight_mm,
            ])->values()->all(),
            'glazings' => Glazing::active()->ordered()->get()->map(fn (Glazing $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'material' => $g->material->value,
                'reflection' => $g->reflection_level->value,
                'max_area_m2' => $g->max_area_m2 !== null ? (float) $g->max_area_m2 : null,
                'description' => $g->description,
            ])->values()->all(),
            'finishings' => Finishing::active()->ordered()->with(['substrates', 'media'])->get()->map(fn (Finishing $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'description' => $f->description,
                'picture' => $f->pictureUrl(),
                'is_default' => $f->is_default,
                'substrate_ids' => $f->substrates->pluck('id')->all(),
            ])->values()->all(),
            'quality_tiers' => QualityTier::active()->ordered()->get()->map(fn (QualityTier $q) => [
                'id' => $q->id,
                'name' => $q->name,
                'description' => $q->description,
                'default_medium_id' => $q->default_medium_id,
                'default_substrate_id' => $q->default_substrate_id,
                'default_glazing_id' => $q->default_glazing_id,
                'default_mat_id' => $q->default_mat_id,
            ])->values()->all(),
            'standard_sizes' => StandardSize::active()->ordered()->get()->map(fn (StandardSize $s) => [
                'label' => $s->label,
                'width_mm' => $s->width_mm,
                'height_mm' => $s->height_mm,
            ])->values()->all(),
            'enums' => [
                'edge_styles' => self::enum(EdgeStyle::options()),
                'usage_rights' => self::enum(UsageRights::options()),
                'own_services' => self::enum(OwnArtworkService::options()),
                'units' => self::enum(LengthUnit::options()),
                'size_references' => self::enum(SizeReference::options()),
            ],
        ];
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, T>  $active
     * @param  Collection<int, T>  $inactive
     * @return Collection<int, T>
     */
    private static function withInactive(Collection $active, Collection $inactive): Collection
    {
        return $active->concat($inactive)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private static function artwork(Artwork $a): array
    {
        return [
            'id' => $a->id,
            'code' => $a->code,
            'title' => $a->title,
            'artist' => $a->artist?->name,
            'type' => $a->type->value,
            'type_label' => $a->type->getLabel(),
            'description' => $a->description,
            // Full names ("Canvas Wall Art › Set of 3 Designs") plus each parent, so filtering by a top-level category finds its subcategories' artworks.
            'categories' => $a->categories
                ->flatMap(fn (Category $c) => array_filter([$c->parent?->name, $c->fullName()]))
                ->unique()->values()->all(),
            'thumb' => ($m = $a->getFirstMedia('main')) ? MediaUrl::for($m, 'thumb') : null,
            'image' => ($m = $a->getFirstMedia('main')) ? MediaUrl::for($m, 'preview') : null,
            'image_detail' => $m ? MediaUrl::for($m, 'detail') : null,
            // The original file, for pixel-level zoom in the full-page viewer.
            'image_full' => $m ? MediaUrl::for($m) : null,
            'native_width_mm' => $a->native_width_mm,
            'native_height_mm' => $a->native_height_mm,
            'min_width_mm' => $a->min_width_mm,
            'max_width_mm' => $a->max_width_mm,
            'allow_custom_size' => $a->allow_custom_size,
            'sizes' => $a->sizePrices->map(fn ($s) => [
                'width_mm' => $s->width_mm,
                'height_mm' => $s->height_mm,
                'label' => StandardSize::defaultLabel($s->width_mm, $s->height_mm),
            ])->values()->all(),
            'default_medium_id' => $a->default_medium_id,
            'medium_ids' => $a->mediums->pluck('id')->all(),
            'substrate_ids' => $a->substrates->pluck('id')->all(),
            'is_featured' => $a->is_featured,
            'is_active' => (bool) $a->is_active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function frame(Frame $f): array
    {
        return [
            'id' => $f->id,
            'code' => $f->code,
            'name' => $f->name,
            'colour' => $f->colour_hex,
            'width_mm' => $f->profile_width_mm,
            'depth_mm' => $f->profile_depth_mm,
            'finish' => $f->finish->value,
            'material' => $f->material->getLabel(),
            'corner' => ($m = $f->getFirstMedia('corner')) ? MediaUrl::for($m, 'thumb') : null,
            // Moulding strip built from the admin's photo: tileable along X, top row = outer edge.
            'strip_url' => $f->stripUrl(),
            'strip_face_mm' => $f->stripUrl() ? $f->profile_width_mm : null,
            'is_floater' => $f->is_floater,
            'profile' => $f->profile?->value ?? 'flat',
            'substrate_ids' => $f->substrates->pluck('id')->all(),
            'max_inner_width_mm' => $f->max_inner_width_mm,
            'max_inner_height_mm' => $f->max_inner_height_mm,
            'is_active' => (bool) $f->is_active,
        ];
    }

    /**
     * Options for the project step and address forms.
     *
     * @return array<string, mixed>
     */
    public static function forProject(): array
    {
        return [
            'project_types' => self::enum(ProjectType::options()),
            'budget_ranges' => self::enum(BudgetRange::options()),
            'preferred_contacts' => self::enum(PreferredContact::options()),
            'states' => self::states(),
        ];
    }

    /**
     * @return list<array{value: int, label: string}>
     */
    public static function states(): array
    {
        return State::active()->ordered()->get(['id', 'name'])
            ->map(fn (State $s) => ['value' => $s->id, 'label' => $s->name])->values()->all();
    }

    /**
     * @param  array<string, string>  $options
     * @return list<array{value: string, label: string}>
     */
    private static function enum(array $options): array
    {
        return collect($options)->map(fn ($label, $value) => ['value' => (string) $value, 'label' => $label])->values()->all();
    }
}
