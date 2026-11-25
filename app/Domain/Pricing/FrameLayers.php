<?php

namespace App\Domain\Pricing;

/**
 * Several frames on one picture: up to 3 stacked layers, innermost first. Each layer is one moulding,
 * or split between two (a primary frame and a second frame):
 *  - top_bottom_sides: top & bottom rails = primary, left & right rails = second;
 *  - halves: top rail + upper halves of the side rails = primary, the bottom half = second.
 */
final class FrameLayers
{
    public const MAX = 3;

    public const SPLIT_TOP_BOTTOM_SIDES = 'top_bottom_sides';

    public const SPLIT_HALVES = 'halves';

    public const MODES = [self::SPLIT_TOP_BOTTOM_SIDES, self::SPLIT_HALVES];

    /**
     * Clean list of layers from stored / posted data (array or JSON string). With nothing usable,
     * a plain frame id means one layer of that frame (lines saved before layers existed).
     *
     * @return list<array{frame_id: int, split: array{frame_id: int, mode: string}|null}>
     */
    public static function normalise(mixed $raw, ?int $frameId = null): array
    {
        if (is_string($raw)) {
            $raw = $raw === '' ? null : json_decode($raw, true);
        }

        $layers = [];
        foreach (is_array($raw) ? array_values($raw) : [] as $layer) {
            $id = is_array($layer) ? (int) ($layer['frame_id'] ?? 0) : 0;
            if ($id <= 0) {
                continue;
            }
            $split = is_array($layer['split'] ?? null) ? $layer['split'] : null;
            $splitId = (int) ($split['frame_id'] ?? 0);
            $mode = $split['mode'] ?? null;
            $layers[] = [
                'frame_id' => $id,
                'split' => $splitId > 0 && in_array($mode, self::MODES, true) ? ['frame_id' => $splitId, 'mode' => $mode] : null,
            ];
            if (count($layers) === self::MAX) {
                break;
            }
        }

        if ($layers === [] && $frameId) {
            $layers[] = ['frame_id' => $frameId, 'split' => null];
        }

        return $layers;
    }

    /**
     * @param  list<array{frame_id: int, split: array{frame_id: int, mode: string}|null}>  $layers
     * @return list<int>
     */
    public static function frameIds(array $layers): array
    {
        $ids = [];
        foreach ($layers as $layer) {
            $ids[] = $layer['frame_id'];
            if ($layer['split']) {
                $ids[] = $layer['split']['frame_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** Customer-facing text, e.g. "Blue 20 mm + Gold 30 mm around · Frame 1 split: top half / bottom half Walnut". */
    public static function describe(array $layers, callable $name): string
    {
        $main = [];
        $splits = [];
        foreach ($layers as $i => $layer) {
            $main[] = $name($layer['frame_id']).($i > 0 ? ' around' : '');
            if ($layer['split']) {
                $splits[] = 'Frame '.($i + 1).' split: '.self::modeLabel($layer['split']['mode']).' '.$name($layer['split']['frame_id']);
            }
        }

        return implode(' · ', array_filter([implode(' + ', $main), ...$splits]));
    }

    public static function modeLabel(string $mode): string
    {
        return $mode === self::SPLIT_HALVES ? 'top half / bottom half' : 'top & bottom / sides';
    }
}
