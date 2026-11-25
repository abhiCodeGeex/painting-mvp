<?php

namespace App\Models;

use App\Enums\FrameFinish;
use App\Enums\FrameMaterial;
use App\Enums\FramePhotoType;
use App\Enums\FrameProfile;
use App\Enums\StockUnit;
use App\Models\Concerns\HasStock;
use App\Models\Concerns\IsMaster;
use App\Support\MediaUrl;
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
    use HasStock, InteractsWithMedia, IsMaster;

    /**
     * Moulding is stocked in metres.
     */
    public function stockUnit(): StockUnit
    {
        return StockUnit::Metre;
    }

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'finish' => FrameFinish::class,
            'material' => FrameMaterial::class,
            'profile' => FrameProfile::class,
            'price_per_m' => 'integer',
            'waste_percent' => 'decimal:2',
            'is_floater' => 'boolean',
            'strip_photo_type' => FramePhotoType::class,
            'strip_flip' => 'boolean',
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
        // Any photo of the frame (corner, whole frame or straight piece) ...
        $this->addMediaCollection('photo')->singleFile()->useDisk('public');
        // ... and the moulding strip built from it (App\Support\FrameStrip). Never uploaded by hand.
        $this->addMediaCollection('strip')->singleFile()->useDisk('public');
    }

    /**
     * Horizontal, tileable moulding strip (top = outer edge, bottom = inner edge), if built.
     */
    public function stripUrl(): ?string
    {
        $strip = $this->getFirstMedia('strip');

        return $strip ? MediaUrl::for($strip) : null;
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')->fit(Fit::Contain, 200, 200)->nonQueued();
    }
}
