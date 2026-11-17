<?php

namespace App\Models;

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

    protected $guarded = ['id'];

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
            'width_mm' => 'integer',
            'height_mm' => 'integer',
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
    public function previewImageUrl(): ?string
    {
        $media = match ($this->source_type) {
            LineSource::Catalog => $this->artwork?->getFirstMedia('main'),
            LineSource::OwnArtwork => $this->getFirstMedia('artwork_photo'),
            LineSource::CustomCreation => $this->getFirstMedia('references'),
        };

        if (! $media || ! MediaUrl::isImage($media)) {
            return null;
        }

        return MediaUrl::for($media, 'preview');
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
