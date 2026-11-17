<?php

namespace App\Support;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * URL for a media file: public catalog images get their normal URL, private customer
 * uploads go through the access-checked route.
 */
final class MediaUrl
{
    public static function for(Media $media, ?string $conversion = null, bool $download = false): string
    {
        if ($media->disk === 'public') {
            return $conversion && $media->hasGeneratedConversion($conversion)
                ? $media->getUrl($conversion)
                : $media->getUrl();
        }

        $params = ['media' => $media->id];
        if ($conversion && $media->hasGeneratedConversion($conversion)) {
            $params['conversion'] = $conversion;
        }
        if ($download) {
            $params['download'] = 1;
        }

        return route('media.show', $params);
    }

    public static function isImage(Media $media): bool
    {
        return str_starts_with((string) $media->mime_type, 'image/');
    }
}
