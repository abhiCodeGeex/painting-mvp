<?php

namespace App\Models;

use App\Enums\ArtworkPricingModel;
use App\Enums\ArtworkType;
use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Catalog artwork (spec §4.2). Price is either fixed per size (size_prices) or per m².
 */
class Artwork extends Model implements HasMedia
{
    use InteractsWithMedia, IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => ArtworkType::class,
            'pricing_model' => ArtworkPricingModel::class,
            'style_tags' => 'array',
            'allow_custom_size' => 'boolean',
            'is_featured' => 'boolean',
            'edition_size' => 'integer',
            'price_per_m2' => 'integer',
            'lead_time_days' => 'integer',
            'native_width_mm' => 'integer',
            'native_height_mm' => 'integer',
            'min_width_mm' => 'integer',
            'min_height_mm' => 'integer',
            'max_width_mm' => 'integer',
            'max_height_mm' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Artist, $this>
     */
    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    /**
     * @return BelongsTo<Medium, $this>
     */
    public function defaultMedium(): BelongsTo
    {
        return $this->belongsTo(Medium::class, 'default_medium_id');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * @return BelongsToMany<Medium, $this>
     */
    public function mediums(): BelongsToMany
    {
        return $this->belongsToMany(Medium::class, 'artwork_medium');
    }

    /**
     * @return BelongsToMany<Substrate, $this>
     */
    public function substrates(): BelongsToMany
    {
        return $this->belongsToMany(Substrate::class);
    }

    /**
     * @return HasMany<ArtworkSizePrice, $this>
     */
    public function sizePrices(): HasMany
    {
        return $this->hasMany(ArtworkSizePrice::class)->orderBy('sort_order');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('main')->singleFile()->useDisk('public');
        $this->addMediaCollection('gallery')->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Contain, 300, 300)->nonQueued();
        $this->addMediaConversion('preview')->fit(Fit::Max, 2048, 2048)->nonQueued();
        // Close-up inspection in the 3D room (loaded only when the customer moves in on the picture).
        $this->addMediaConversion('detail')->fit(Fit::Max, 4096, 4096)->nonQueued();
    }
}
