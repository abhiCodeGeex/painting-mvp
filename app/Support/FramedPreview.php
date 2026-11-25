<?php

namespace App\Support;

use App\Enums\FrameFinish;
use App\Models\EnquiryLine;
use App\Models\Frame;
use App\Models\Mat;

/**
 * Builds the inline-CSS boxes for a framed picture preview, drawn to scale from real
 * dimensions (mm): picture → mat → frame, optionally on a wall colour.
 * Used by the admin panel (Blade). The customer portal has the same logic in React.
 */
class FramedPreview
{
    public const SAMPLE_IMAGE_GRADIENT = 'linear-gradient(135deg,#f6d365 0%,#fda085 35%,#a1c4fd 70%,#5b7db1 100%)';

    /**
     * @return array<string, mixed>
     */
    public static function make(
        int $widthMm,
        int $heightMm,
        ?string $imageUrl = null,
        ?Frame $frame = null,
        ?Mat $mat = null,
        ?int $matBorderMm = null,
        ?string $wallColour = null,
        int $maxPx = 220,
        ?string $frameColour = null,
        ?int $frameWidthMm = null,
        FrameFinish|string|null $finish = null,
        int $depthMm = 0,
        int $rotation = 0,
    ): array {
        $widthMm = max(1, $widthMm);
        $heightMm = max(1, $heightMm);

        // A quarter turn means the hung width and height are swapped.
        $rotation = ((int) round($rotation / 90) * 90 % 360 + 360) % 360;
        if ($rotation === 90 || $rotation === 270) {
            [$widthMm, $heightMm] = [$heightMm, $widthMm];
        }

        $frameColour ??= $frame?->colour_hex;
        $frameWidthMm ??= $frame?->profile_width_mm ?? 0;
        $finish = $finish instanceof FrameFinish ? $finish : FrameFinish::tryFrom((string) ($finish ?? $frame?->finish?->value));

        $matBorderMm = $mat ? ($matBorderMm ?? $mat->default_border_mm) : 0;
        $matBottomExtra = $mat?->bottom_weight_mm ?? 0;

        $outerW = $widthMm + 2 * $matBorderMm + 2 * ($frameColour ? $frameWidthMm : 0);
        $outerH = $heightMm + 2 * $matBorderMm + $matBottomExtra + 2 * ($frameColour ? $frameWidthMm : 0);
        $scale = $maxPx / max($outerW, $outerH);

        $px = fn (float $mm, int $min = 0): string => max($min, round($mm * $scale, 1)).'px';

        $frameStyle = '';
        if ($frameColour) {
            $frameStyle = sprintf(
                'display:inline-block;padding:%s;background:%s;box-shadow:%s;',
                $px($frameWidthMm, 2),
                self::frameBackground($frameColour, $finish),
                'inset 0 0 0 1px rgba(0,0,0,.25), inset 2px 2px 3px rgba(255,255,255,.25), inset -2px -2px 3px rgba(0,0,0,.3), 0 6px 14px rgba(0,0,0,.35)',
            );
        } elseif ($depthMm > 0) {
            // Unframed canvas: show its thickness as a shadow.
            $frameStyle = sprintf('display:inline-block;box-shadow:%s %s 0 rgba(0,0,0,.35), 0 6px 14px rgba(0,0,0,.3);', $px($depthMm / 3, 1), $px($depthMm / 3, 1));
        } else {
            $frameStyle = 'display:inline-block;box-shadow:0 4px 10px rgba(0,0,0,.25);';
        }

        $matStyle = $mat
            ? sprintf('display:block;padding:%s %s %s %s;background:%s;', $px($matBorderMm, 2), $px($matBorderMm, 2), $px($matBorderMm + $matBottomExtra, 2), $px($matBorderMm, 2), $mat->colour_hex)
            : 'display:block;';

        $background = $imageUrl ? "#ddd url('".e($imageUrl)."')" : self::SAMPLE_IMAGE_GRADIENT;
        $matCore = $mat ? 'box-shadow:0 0 0 '.$px(2, 1).' '.$mat->core_colour_hex.';' : '';

        // Width and height swapped (a quarter turn): the upright picture is stretched to fill, never turned or cropped.
        $imageInnerStyle = null;
        $imageStyle = sprintf(
            'display:block;width:%s;height:%s;background:%s;background-size:%s;background-position:center;%s',
            $px($widthMm, 4), $px($heightMm, 4), $background, $rotation === 0 ? 'cover' : '100% 100%', $matCore,
        );

        return [
            'wallStyle' => $wallColour
                ? "display:flex;align-items:center;justify-content:center;padding:24px;border-radius:8px;background:{$wallColour};"
                : 'display:inline-flex;align-items:center;justify-content:center;',
            'frameStyle' => $frameStyle,
            'matStyle' => $matStyle,
            'imageStyle' => $imageStyle,
            'imageInnerStyle' => $imageInnerStyle,
            'rotation' => $rotation,
            'hasImage' => (bool) $imageUrl,
        ];
    }

    /**
     * Preview data for an enquiry line, using its chosen frame / mat / wall colour.
     *
     * @return array<string, mixed>
     */
    public static function forLine(EnquiryLine $line, int $maxPx = 220, bool $withWall = true): array
    {
        return self::make(
            widthMm: $line->width_mm,
            heightMm: $line->height_mm,
            imageUrl: $line->previewImageUrl(),
            frame: $line->frame,
            mat: $line->mat,
            matBorderMm: $line->mat_border_mm,
            wallColour: $withWall ? ($line->wall_colour ?: '#EDE8E1') : null,
            maxPx: $maxPx,
            depthMm: $line->frame ? 0 : (int) $line->substrate?->depth_mm,
            rotation: (int) $line->image_rotation,
        );
    }

    private static function frameBackground(string $colour, ?FrameFinish $finish): string
    {
        return match ($finish) {
            FrameFinish::Gloss => "linear-gradient(135deg, rgba(255,255,255,.35) 0%, rgba(255,255,255,0) 40%), {$colour}",
            FrameFinish::Metallic => "linear-gradient(135deg, rgba(255,255,255,.55) 0%, rgba(255,255,255,.05) 30%, rgba(0,0,0,.15) 55%, rgba(255,255,255,.35) 80%), {$colour}",
            FrameFinish::Satin => "linear-gradient(135deg, rgba(255,255,255,.15) 0%, rgba(255,255,255,0) 50%), {$colour}",
            default => $colour,
        };
    }
}
