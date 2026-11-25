<?php

namespace App\Models;

use App\Domain\Pricing\FrameLayers;
use App\Enums\EdgeStyle;
use App\Enums\LengthUnit;
use App\Enums\LineSource;
use App\Enums\SizeReference;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One piece in an enquiry: a catalog artwork, a new custom creation or the customer's own
 * artwork, with size, materials and framing (spec §6.2).
 */
class EnquiryLine extends Model implements HasMedia
{
    use InteractsWithMedia;

    /** Quarter turns the customer can apply to the picture (degrees clockwise). */
    public const ROTATIONS = [0, 90, 180, 270];

    protected $guarded = ['id'];

    protected $attributes = ['image_rotation' => 0];

    protected function casts(): array
    {
        return [
            'source_type' => LineSource::class,
            'size_reference' => SizeReference::class,
            'unit' => LengthUnit::class,
            'edge_style' => EdgeStyle::class,
            'custom_brief' => 'array',
            'own_artwork' => 'array',
            'rights_confirmed_at' => 'datetime',
            'frame_advice_requested' => 'boolean',
            'frame_layers' => 'array',
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'image_rotation' => 'integer',
            'quantity' => 'integer',
            'mat_border_mm' => 'integer',
            'wall_width_mm' => 'integer',
            'wall_height_mm' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Enquiry, $this>
     */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /**
     * @return BelongsTo<Artwork, $this>
     */
    public function artwork(): BelongsTo
    {
        return $this->belongsTo(Artwork::class)->withTrashed();
    }

    /**
     * @return BelongsTo<QualityTier, $this>
     */
    public function qualityTier(): BelongsTo
    {
        return $this->belongsTo(QualityTier::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Medium, $this>
     */
    public function medium(): BelongsTo
    {
        return $this->belongsTo(Medium::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Substrate, $this>
     */
    public function substrate(): BelongsTo
    {
        return $this->belongsTo(Substrate::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Frame, $this>
     */
    public function frame(): BelongsTo
    {
        return $this->belongsTo(Frame::class)->withTrashed();
    }

    /**
     * Frame layers, innermost first (each optionally split between two mouldings). A line saved with only
     * frame_id is one plain layer.
     *
     * @return list<array{frame_id: int, split: array{frame_id: int, mode: string}|null}>
     */
    public function frameLayers(): array
    {
        return FrameLayers::normalise($this->frame_layers, $this->frame_id);
    }

    /** e.g. "Oak 20 mm + Gold 30 mm around · Frame 1 split: top half / bottom half Walnut", or null without a frame. */
    public function frameLayersLabel(): ?string
    {
        $layers = $this->frameLayers();
        if ($layers === []) {
            return null;
        }
        $frames = Frame::withTrashed()->whereIn('id', FrameLayers::frameIds($layers))->pluck('name', 'id');

        return FrameLayers::describe($layers, fn (int $id): string => $frames[$id] ?? 'Frame #'.$id);
    }

    /**
     * @return BelongsTo<Mat, $this>
     */
    public function mat(): BelongsTo
    {
        return $this->belongsTo(Mat::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Glazing, $this>
     */
    public function glazing(): BelongsTo
    {
        return $this->belongsTo(Glazing::class)->withTrashed();
    }

    /**
     * @return BelongsToMany<Finishing, $this>
     */
    public function finishings(): BelongsToMany
    {
        return $this->belongsToMany(Finishing::class)->withTrashed();
    }

    public function registerMediaCollections(): void
    {
        // Custom creation: sketches / mood images. Own artwork: photo and print file.
        // Private disk: served through PrivateMediaController with an access check.
        $this->addMediaCollection('references')->useDisk('local');
        $this->addMediaCollection('artwork_photo')->singleFile()->useDisk('local');
        $this->addMediaCollection('hi_res')->singleFile()->useDisk('local');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('preview')
            ->fit(Fit::Max, 1600, 1600)
            ->performOnCollections('references', 'artwork_photo')
            ->nonQueued();
        // Close-up inspection in the 3D room (loaded only when the customer moves in on the picture).
        $this->addMediaConversion('detail')
            ->fit(Fit::Max, 4096, 4096)
            ->performOnCollections('references', 'artwork_photo')
            ->nonQueued();
        $this->addMediaConversion('thumb')
            ->fit(Fit::Contain, 300, 300)
            ->performOnCollections('references', 'artwork_photo')
            ->nonQueued();
    }

    /** Title shown in lists: artwork title, custom title or a generic label. */
    public function displayTitle(): string
    {
        return match ($this->source_type) {
            LineSource::Catalog => $this->artwork?->title ?? 'Catalog artwork',
            LineSource::CustomCreation => $this->title ?: 'New custom creation',
            LineSource::OwnArtwork => $this->title ?: 'Customer artwork',
        };
    }

    /**
     * Image used for previews: catalog main image, the customer's own artwork photo, or the
     * first reference image of a custom creation.
     */
    public function previewImageUrl(?string $conversion = 'preview'): ?string
    {
        $media = match ($this->source_type) {
            LineSource::Catalog => $this->artwork?->getFirstMedia('main'),
            LineSource::OwnArtwork => $this->getFirstMedia('artwork_photo'),
            LineSource::CustomCreation => $this->getFirstMedia('references'),
        };

        if (! $media || ! MediaUrl::isImage($media)) {
            return null;
        }

        return MediaUrl::for($media, $conversion);
    }

    /** True when the customer swapped the painting's width and height (stored as a quarter turn). */
    public function sidesSwapped(): bool
    {
        return intdiv((int) $this->image_rotation, 90) % 2 === 1;
    }

    /** Production note when the sides are swapped: the picture stays upright and is stretched to the swapped size. */
    public function rotationLabel(): ?string
    {
        return $this->sidesSwapped() ? 'Width and height swapped: print the picture upright, stretched to fill' : null;
    }

    /**
     * The size as the piece hangs (width and height swapped when the customer chose Rotate), noted as such —
     * for customer-facing summaries. The stored size stays the painting's own.
     */
    public function hungSizeLabel(): string
    {
        return $this->sidesSwapped() ? $this->printSizeLabel().' · sides swapped' : $this->sizeLabel();
    }

    /** The size to make (and hang): width and height swapped when the customer chose Rotate. */
    public function printSizeLabel(): string
    {
        if (! $this->sidesSwapped()) {
            return $this->sizeLabel();
        }

        return (clone $this)->forceFill(['width_mm' => $this->height_mm, 'height_mm' => $this->width_mm])->sizeLabel();
    }

    /** e.g. "50 × 70 cm". */
    public function sizeLabel(): string
    {
        if ($this->unit === LengthUnit::Inch) {
            $in = fn (int $mm): string => rtrim(rtrim(number_format($mm / 25.4, 1, '.', ''), '0'), '.');

            return $in($this->width_mm).' × '.$in($this->height_mm).' in';
        }

        return StandardSize::defaultLabel($this->width_mm, $this->height_mm);
    }
}
