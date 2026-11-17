<?php

namespace App\Models;

use App\Enums\FrameFinish;
use App\Enums\FrameMaterial;
use App\Models\Concerns\IsMaster;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Frame moulding (spec §4.2). Width/depth/colour/finish also drive the 3D preview (§10).
 */
class Frame extends Model implements HasMedia
{
    use InteractsWithMedia, IsMaster;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'finish' => FrameFinish::class,
            'material' => FrameMaterial::class,
            'price_per_m' => 'integer',
            'waste_percent' => 'decimal:2',
            'is_floater' => 'boolean',
            'profile_width_mm' => 'integer',
            'profile_depth_mm' => 'integer',
            'rebate_depth_mm' => 'integer',
            'min_inner_width_mm' => 'integer',
            'min_inner_height_mm' => 'integer',
            'max_inner_width_mm' => 'integer',
            'max_inner_height_mm' => 'integer',
            'max_perimeter_mm' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Substrate, $this>
     */
    public function substrates(): BelongsToMany
    {
        return $this->belongsToMany(Substrate::class);
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('corner')->singleFile()->useDisk('public');
        $this->addMediaCollection('profile')->singleFile()->useDisk('public');
        $this->addMediaCollection('texture')->singleFile()->useDisk('public');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Contain, 200, 200)->nonQueued();
    }
}
