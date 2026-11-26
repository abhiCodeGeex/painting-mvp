<?php

namespace App\Domain\Enquiries;

use App\Domain\Pricing\FrameLayers;
use App\Domain\Pricing\LineConfig;
use App\Domain\Pricing\PricingEngine;
use App\Enums\LineSource;
use App\Models\Artwork;
use App\Models\Frame;
use App\Models\Glazing;
use App\Models\Mat;
use App\Models\Medium;
use App\Models\Substrate;

/**
 * Checks that the options chosen for an enquiry line fit together (spec §4.3).
 * Errors block saving; warnings are shown to the customer and to staff.
 * The portal hides most incompatible options up front; this is the server-side guard.
 */
class LineCompatibility
{
    /** @var array<string, string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    /**
     * @param  array<string, mixed>  $line  validated line data (dimensions in mm)
     */
    public static function check(array $line): self
    {
        $result = new self;

        $width = (int) $line['width_mm'];
        $height = (int) $line['height_mm'];
        $source = LineSource::from($line['source_type']);
        $outer = ($line['size_reference'] ?? 'image') === 'outer';
        if ($outer) {
            // An outer size includes the frames and mat: the checks below are about the picture inside,
            // worked out exactly as the price is.
            [$width, $height] = self::pictureSize($line);
        }

        $artwork = $source === LineSource::Catalog && ! empty($line['artwork_id']) ? Artwork::with(['mediums', 'substrates'])->find($line['artwork_id']) : null;
        $medium = ! empty($line['medium_id']) ? Medium::with('substrates')->find($line['medium_id']) : null;
        $substrate = ! empty($line['substrate_id']) ? Substrate::find($line['substrate_id']) : null;
        $frame = ! empty($line['frame_id']) ? Frame::with('substrates')->find($line['frame_id']) : null;
        $mat = ! empty($line['mat_id']) ? Mat::find($line['mat_id']) : null;
        $glazing = ! empty($line['glazing_id']) ? Glazing::find($line['glazing_id']) : null;

        if ($artwork) {
            if (! $artwork->allow_custom_size && $artwork->sizePrices()->where(['width_mm' => $width, 'height_mm' => $height])->doesntExist()) {
                $result->errors['width_mm'] = $outer
                    ? 'This artwork comes in the listed sizes only: choose “Artwork / image size” and pick one.'
                    : 'This artwork is only available in the listed sizes.';
            }
            if (($artwork->max_width_mm && $width > $artwork->max_width_mm) || ($artwork->max_height_mm && $height > $artwork->max_height_mm)) {
                $result->errors['width_mm'] = 'This artwork cannot be made this large.';
            }
            if (($artwork->min_width_mm && $width < $artwork->min_width_mm) || ($artwork->min_height_mm && $height < $artwork->min_height_mm)) {
                $result->errors['width_mm'] = 'This artwork cannot be made this small.';
            }
            if ($medium && $artwork->mediums->isNotEmpty() && ! $artwork->mediums->contains($medium)) {
                $result->errors['medium_id'] = 'This medium is not available for this artwork.';
            }
            if ($substrate && $artwork->substrates->isNotEmpty() && ! $artwork->substrates->contains($substrate)) {
                $result->errors['substrate_id'] = 'This surface is not available for this artwork.';
            }
        }

        if ($medium && $substrate && $medium->substrates->isNotEmpty() && ! $medium->substrates->contains($substrate)) {
            $result->errors['substrate_id'] = "{$medium->name} cannot be done on {$substrate->name}.";
        }

        if ($substrate) {
            if (! self::fits($width, $height, $substrate->max_width_mm, $substrate->max_height_mm)) {
                $result->errors['width_mm'] = "{$substrate->name} is not available in this size.";
            }
            if ($glazing && $glazing->material->value !== 'none' && ! $substrate->allows_glazing) {
                $result->errors['glazing_id'] = "{$substrate->name} is not glazed. Choose “No glazing”.";
            }
            if ($mat && ! $substrate->allows_mat) {
                $result->errors['mat_id'] = "{$substrate->name} cannot have a mat.";
            }
            if ($substrate->requires_frame && ! $frame && empty($line['frame_advice_requested'])) {
                $result->errors['frame_id'] = "{$substrate->name} must be framed. Choose a frame or ask for our advice.";
            }
        }

        if ($frame) {
            // Every moulding: the inner frame around the picture (+ mat), each outer layer around the
            // frames inside it. A split layer's two mouldings share one opening.
            $border = $mat ? (int) ($line['mat_border_mm'] ?? $mat->default_border_mm) : 0;
            $openW = $width + 2 * $border;
            $openH = $height + 2 * $border + ($mat?->bottom_weight_mm ?? 0);
            $layers = FrameLayers::normalise($line['frame_layers'] ?? null, (int) $frame->id);
            $frames = Frame::with('substrates')->whereIn('id', FrameLayers::frameIds($layers))->get()->keyBy('id');
            foreach ($layers as $i => $layer) {
                $parts = ['frame_id' => $frames->get($layer['frame_id'])];
                if ($layer['split']) {
                    $parts['split.frame_id'] = $frames->get($layer['split']['frame_id']);
                }
                $widest = 0;
                foreach ($parts as $key => $f) {
                    if (! $f) {
                        continue;
                    }
                    $field = $i === 0 && $key === 'frame_id' ? 'frame_id' : "frame_layers.$i.$key";
                    $tooBig = ! self::fits($openW, $openH, $f->max_inner_width_mm, $f->max_inner_height_mm)
                        || ($f->max_perimeter_mm && 2 * ($openW + $openH) > $f->max_perimeter_mm);
                    if ($tooBig) {
                        $result->errors[$field] = "{$f->name} is not available for this size.";
                    }
                    if ($substrate && $f->substrates->isNotEmpty() && ! $f->substrates->contains($substrate)) {
                        $result->errors[$field] = "{$f->name} does not suit {$substrate->name}.";
                    }
                    $widest = max($widest, (int) $f->profile_width_mm);
                }
                $openW += 2 * $widest;
                $openH += 2 * $widest;
            }
        }

        if ($glazing) {
            $area = ($width / 1000) * ($height / 1000);
            if (($glazing->max_area_m2 && $area > (float) $glazing->max_area_m2)
                || ! self::fits($width, $height, $glazing->max_width_mm, $glazing->max_height_mm)) {
                $result->errors['glazing_id'] = "{$glazing->name} is not available this large — choose acrylic instead.";
            }
        }

        if ($mat) {
            $border = (int) ($line['mat_border_mm'] ?? $mat->default_border_mm);
            if (! self::fits($width + 2 * $border, $height + 2 * $border, $mat->max_sheet_width_mm, $mat->max_sheet_height_mm)) {
                $result->warnings[] = "The mat is larger than one sheet of {$mat->name}; we may need to join it or suggest another option.";
            }
        }

        return $result;
    }

    /**
     * The picture inside an outer size: outer − frames − rebate allowance − mat (as PricingEngine::dimensions).
     *
     * @param  array<string, mixed>  $line
     * @return array{0: int, 1: int}
     */
    private static function pictureSize(array $line): array
    {
        $layers = FrameLayers::normalise($line['frame_layers'] ?? null, ! empty($line['frame_id']) ? (int) $line['frame_id'] : null);
        $frames = Frame::whereIn('id', FrameLayers::frameIds($layers))->get()->keyBy('id');
        $loaded = [];
        foreach ($layers as $layer) {
            if (! $primary = $frames->get($layer['frame_id'])) {
                continue;
            }
            $second = $layer['split'] ? $frames->get($layer['split']['frame_id']) : null;
            $loaded[] = ['frame' => $primary, 'split' => $second ? ['frame' => $second, 'mode' => $layer['split']['mode']] : null];
        }
        // A mat only comes with a frame (or frame advice), as in pricing.
        $framed = $loaded !== [] || ! empty($line['frame_advice_requested']);
        $mat = $framed && ! empty($line['mat_id']) ? Mat::find($line['mat_id']) : null;
        $dims = app(PricingEngine::class)->dimensions(LineConfig::fromArray($line), $loaded[0]['frame'] ?? null, $mat, $loaded ?: null);

        return [(int) $dims['image_w'], (int) $dims['image_h']];
    }

    /**
     * Does a w × h piece fit within a max size in either orientation? Null limits = no limit.
     */
    public static function fits(int $w, int $h, ?int $maxW, ?int $maxH): bool
    {
        if (! $maxW && ! $maxH) {
            return true;
        }

        $limitLong = max((int) $maxW, (int) $maxH);
        $limitShort = ($maxW && $maxH) ? min($maxW, $maxH) : $limitLong;

        return max($w, $h) <= $limitLong && min($w, $h) <= $limitShort;
    }
}
