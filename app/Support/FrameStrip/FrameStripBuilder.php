<?php

namespace App\Support\FrameStrip;

use App\Enums\FramePhotoType;
use App\Models\Frame;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps a frame's 'strip' media in step with its 'photo': builds it when the photo,
 * photo type or flip changed, removes it when the photo is gone.
 */
final class FrameStripBuilder
{
    /** Bump to rebuild every strip after changing the extraction. */
    public const VERSION = 1;

    public const BUILT = 'built';

    public const UP_TO_DATE = 'up-to-date';

    public const REMOVED = 'removed';

    public const NO_PHOTO = 'no-photo';

    public const FAILED = 'failed';

    public function __construct(private readonly MouldingStripExtractor $extractor) {}

    public function build(Frame $frame, bool $force = false): string
    {
        $frame->unsetRelation('media');
        $photo = $frame->getFirstMedia('photo');
        $strip = $frame->getFirstMedia('strip');

        if (! $photo) {
            $strip?->delete();
            $this->setError($frame, null);

            return $strip ? self::REMOVED : self::NO_PHOTO;
        }

        $type = $frame->strip_photo_type ?? FramePhotoType::Auto;
        $flip = (bool) $frame->strip_flip;
        $signature = implode(':', [self::VERSION, $photo->id, $type->value, (int) $flip]);
        if (! $force && $strip && $strip->getCustomProperty('signature') === $signature) {
            return self::UP_TO_DATE;
        }

        $source = tempnam(sys_get_temp_dir(), 'frame-photo');
        $target = tempnam(sys_get_temp_dir(), 'frame-strip').'.webp';

        try {
            $stream = $photo->stream();
            file_put_contents($source, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $result = $this->extractor->fromFile($source, $type, $flip);
            $result->saveWebp($target);

            $frame->addMedia($target)
                ->usingName($frame->name.' moulding strip')
                ->usingFileName(Str::slug($frame->code ?: 'frame').'-strip.webp')
                ->withCustomProperties([
                    'signature' => $signature,
                    'detected_type' => $result->detectedType->value,
                    'width' => $result->width(),
                    'height' => $result->height(),
                ])
                ->toMediaCollection('strip');
            $this->setError($frame, null);

            return self::BUILT;
        } catch (Throwable $e) {
            if (! $e instanceof StripException) {
                report($e);
            }
            $frame->getFirstMedia('strip')?->delete();
            $this->setError($frame, Str::limit($e instanceof StripException ? $e->getMessage() : 'The strip could not be built from this photo.', 250));

            return self::FAILED;
        } finally {
            @unlink($source);
            @unlink($target);
            $frame->unsetRelation('media');
        }
    }

    private function setError(Frame $frame, ?string $error): void
    {
        if ($frame->strip_error !== $error) {
            $frame->forceFill(['strip_error' => $error])->saveQuietly();
        }
    }
}
