<?php

namespace App\Models;

use App\Enums\PricingType;
use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Finishing & hardware: varnish, D-rings, French cleat, dust cover…
 */
class Finishing extends Model implements HasMedia
{
    use InteractsWithMedia, IsMaster;

    /** Seeded illustration per finishing (database/seeders/assets/finishings). */
    public const PICTURES = [
        'D-rings + wire' => 'd-rings-wire.svg',
        'French cleat' => 'french-cleat.svg',
        'Protective varnish' => 'protective-varnish.svg',
        'Dust cover backing' => 'dust-cover-backing.svg',
        'Security hangers' => 'security-hangers.svg',
    ];

    public function registerMediaCollections(): void
    {
        // Product picture or diagram (SVG illustrations allowed, so no image conversions).
        $this->addMediaCollection('picture')->singleFile()->useDisk('public');
    }

    public function pictureUrl(): ?string
    {
        return $this->getFirstMedia('picture')?->getUrl();
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pricing_type' => PricingType::class,
            'cost' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Substrate, $this>
     */
    public function substrates(): BelongsToMany
    {
        return $this->belongsToMany(Substrate::class);
    }
}
