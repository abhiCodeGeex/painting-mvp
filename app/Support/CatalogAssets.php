<?php

namespace App\Support;

use Spatie\MediaLibrary\HasMedia;

/**
 * Bundled catalog pictures (database/seeders/assets): frame corner photos and finishing
 * illustrations. Seeders attach them so a fresh install looks complete.
 */
class CatalogAssets
{
    /** null = automatic (on, except while running tests). */
    public static ?bool $enabled = null;

    public static function path(string $folder, string $file): string
    {
        $dir = database_path("seeders/assets/{$folder}");
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return "{$dir}/{$file}";
    }

    /**
     * Copies the file into the model's media collection. Skips when the collection already has
     * an image (an admin upload wins) unless $replace is true.
     */
    public static function attach(HasMedia $model, string $collection, string $path, bool $replace = false): void
    {
        // Tests seed master data constantly; they opt in (with a fake disk) when they need pictures.
        $enabled = self::$enabled ?? ! app()->runningUnitTests();
        if (! $enabled || ! is_file($path) || (! $replace && $model->getFirstMedia($collection))) {
            return;
        }

        $model->addMedia($path)->preservingOriginal()->toMediaCollection($collection);
    }
}
