<?php

namespace App\Support\FrameStrip;

use App\Enums\FramePhotoType;
use GdImage;

/**
 * A finished strip: horizontal, tileable along X, top row = outer edge, bottom row = inner edge.
 */
final class MouldingStrip
{
    public function __construct(
        public readonly GdImage $image,
        public readonly FramePhotoType $detectedType,
    ) {}

    public function width(): int
    {
        return imagesx($this->image);
    }

    public function height(): int
    {
        return imagesy($this->image);
    }

    public function saveWebp(string $path, int $quality = 92): void
    {
        imagewebp($this->image, $path, $quality);
    }
}
