<?php

namespace App\Support\FrameStrip;

use GdImage;

/**
 * Finds moulding arms in a busy photo (marble, desk clutter, uneven light) by their
 * texture: a moulding is an extruded profile, so its grain, ribs and edges are long
 * parallel lines. A structure tensor gives every pixel a line direction and coherence;
 * the arms are dense bands of coherent lines sharing one direction.
 *
 * Coordinates of an arm are "centred": u along the arm, v across it, both measured from
 * the analysis copy's centre in analysis pixels.
 */
final class TextureArms
{
    public int $w;

    public int $h;

    /** analysis px per source px */
    public float $scale;

    /** @var array<int, float> line direction in degrees [0, 180) of valid pixels, keyed by pixel index */
    private array $dir = [];

    public static function of(GdImage $image, int $max): self
    {
        $t = new self;
        $W = imagesx($image);
        $H = imagesy($image);
        $s = min(1.0, $max / max($W, $H));
        $t->w = $w = max(8, (int) round($W * $s));
        $t->h = $h = max(8, (int) round($H * $s));
        $t->scale = $w / $W;

        $small = imagecreatetruecolor($w, $h);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $w, $h, $W, $H);
        $gray = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($small, $x, $y);
                $gray[] = 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);
            }
        }

        // Sobel gradients → structure tensor products.
        $n = $w * $h;
        $jxx = array_fill(0, $n, 0.0);
        $jyy = array_fill(0, $n, 0.0);
        $jxy = array_fill(0, $n, 0.0);
        for ($y = 1; $y < $h - 1; $y++) {
            for ($x = 1; $x < $w - 1; $x++) {
                $i = $y * $w + $x;
                $gx = ($gray[$i - $w + 1] + 2 * $gray[$i + 1] + $gray[$i + $w + 1]) - ($gray[$i - $w - 1] + 2 * $gray[$i - 1] + $gray[$i + $w - 1]);
                $gy = ($gray[$i + $w - 1] + 2 * $gray[$i + $w] + $gray[$i + $w + 1]) - ($gray[$i - $w - 1] + 2 * $gray[$i - $w] + $gray[$i - $w + 1]);
                $jxx[$i] = $gx * $gx;
                $jyy[$i] = $gy * $gy;
                $jxy[$i] = $gx * $gy;
            }
        }
        $r = 2;
        $jxx = self::boxBlur($jxx, $w, $h, $r);
        $jyy = self::boxBlur($jyy, $w, $h, $r);
        $jxy = self::boxBlur($jxy, $w, $h, $r);

        $energies = [];
        for ($i = 0; $i < $n; $i += 7) {
            $energies[] = $jxx[$i] + $jyy[$i];
        }
        sort($energies);
        $eThreshold = max(400.0, $energies[(int) (count($energies) * 0.55)] ?? 0);

        for ($i = 0; $i < $n; $i++) {
            $e = $jxx[$i] + $jyy[$i];
            if ($e < $eThreshold) {
                continue;
            }
            $d = $jxx[$i] - $jyy[$i];
            $coherence = sqrt($d * $d + 4 * $jxy[$i] * $jxy[$i]) / $e;
            if ($coherence < 0.55) {
                continue;
            }
            $gradient = rad2deg(0.5 * atan2(2 * $jxy[$i], $d));
            $t->dir[$i] = fmod($gradient + 90 + 360, 180);
        }

        return $t;
    }

    /**
     * Dominant line directions (degrees, -90 < θ ≤ 90), strongest first.
     *
     * @return list<float>
     */
    public function directions(int $limit = 3): array
    {
        $hist = array_fill(0, 180, 0.0);
        foreach ($this->dir as $d) {
            $hist[((int) round($d)) % 180]++;
        }
        $smooth = [];
        for ($b = 0; $b < 180; $b++) {
            $sum = 0.0;
            for ($k = -4; $k <= 4; $k++) {
                $sum += $hist[($b + $k + 180) % 180];
            }
            $smooth[$b] = $sum;
        }

        $peaks = [];
        $top = max($smooth) ?: 1;
        for ($p = 0; $p < $limit; $p++) {
            $best = array_search(max($smooth), $smooth, true);
            if ($smooth[$best] < 0.15 * $top || $smooth[$best] <= 0) {
                break;
            }
            $peaks[] = (float) ($best > 90 ? $best - 180 : $best);
            for ($k = -25; $k <= 25; $k++) {
                $smooth[($best + $k + 180) % 180] = -1;
            }
        }

        return $peaks;
    }

    /**
     * Dense bands of coherent lines running in direction θ.
     *
     * @return list<array{theta: float, v0: float, v1: float, u0: float, u1: float, score: float}>
     */
    public function bands(float $theta): array
    {
        $t = deg2rad($theta);
        $cos = cos($t);
        $sin = sin($t);
        $cx = $this->w / 2;
        $cy = $this->h / 2;
        $half = (int) ceil(hypot($this->w, $this->h) / 2) + 2;
        $cell = 6;

        /** @var array<int, array<int, int>> $grid v => u-cell => count */
        $grid = [];
        foreach ($this->dir as $i => $d) {
            $diff = abs(fmod($d - $theta + 360, 180));
            if (min($diff, 180 - $diff) > 10) {
                continue;
            }
            $x = $i % $this->w - $cx;
            $y = intdiv($i, $this->w) - $cy;
            $v = (int) round(-$x * $sin + $y * $cos) + $half;
            $u = (int) floor(($x * $cos + $y * $sin + $half) / $cell);
            $grid[$v][$u] = ($grid[$v][$u] ?? 0) + 1;
        }
        if ($grid === []) {
            return [];
        }

        $occ = [];
        for ($v = 0; $v <= 2 * $half; $v++) {
            $occ[$v] = isset($grid[$v]) ? count(array_filter($grid[$v], fn ($c) => $c >= 2)) : 0;
        }
        $smooth = [];
        foreach ($occ as $v => $o) {
            $smooth[$v] = (($occ[$v - 1] ?? 0) + $o + ($occ[$v + 1] ?? 0)) / 3;
        }
        $max = max($smooth);
        if ($max < 4) {
            return [];
        }

        // Runs above 35 % of the busiest line, small gaps (smooth stripes between ribs) bridged.
        $runs = [];
        $start = null;
        foreach ($smooth as $v => $o) {
            if ($o >= 0.35 * $max) {
                $start ??= $v;
                $end = $v;
            } elseif ($start !== null) {
                $runs[] = [$start, $end];
                $start = null;
            }
        }
        if ($start !== null) {
            $runs[] = [$start, $end];
        }
        $merged = [];
        foreach ($runs as $run) {
            $last = end($merged);
            if ($last && max(2, 0.3 * ($run[1] - $last[0])) >= $run[0] - $last[1] - 1) {
                $merged[count($merged) - 1][1] = $run[1];
            } else {
                $merged[] = $run;
            }
        }

        $bands = [];
        foreach ($merged as [$v0, $v1]) {
            $width = $v1 - $v0 + 1;
            if ($width < 3) {
                continue;
            }
            // Along-arm extent: cells crossed by at least 30 % of the band's lines.
            $cols = [];
            for ($v = $v0; $v <= $v1; $v++) {
                foreach ($grid[$v] ?? [] as $u => $c) {
                    $cols[$u] = ($cols[$u] ?? 0) + 1;
                }
            }
            ksort($cols);
            $best = null;
            $runStart = null;
            $prev = null;
            $filled = 0;
            foreach ($cols as $u => $rows) {
                if ($rows < 0.3 * $width) {
                    continue;
                }
                if ($runStart === null || $u - $prev > 3) {
                    $runStart = $u;
                    $filled = 0;
                }
                $filled++;
                $prev = $u;
                if ($best === null || $u - $runStart > $best[1] - $best[0]) {
                    $best = [$runStart, $u, $filled];
                }
            }
            if ($best === null) {
                continue;
            }
            $length = ($best[1] - $best[0] + 1) * $cell;
            $bands[] = [
                'theta' => $theta,
                'v0' => $v0 - $half - 0.5,
                'v1' => $v1 - $half + 0.5,
                'u0' => $best[0] * $cell - $half,
                'u1' => ($best[1] + 1) * $cell - $half,
                'score' => $width * $length * ($best[2] / ($best[1] - $best[0] + 1)),
            ];
        }

        usort($bands, fn ($a, $b) => $b['score'] <=> $a['score']);

        return $bands;
    }

    /**
     * Centre of an arm (centred analysis coordinates → x, y).
     *
     * @param  array{theta: float, v0: float, v1: float, u0: float, u1: float, score: float}  $arm
     * @return array{float, float}
     */
    public static function point(array $arm, float $u, float $v): array
    {
        $t = deg2rad($arm['theta']);

        return [$u * cos($t) - $v * sin($t), $u * sin($t) + $v * cos($t)];
    }

    /**
     * @param  array<int, float>  $a
     * @return array<int, float>
     */
    private static function boxBlur(array $a, int $w, int $h, int $r): array
    {
        $tmp = [];
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            $sum = 0.0;
            for ($x = -$r; $x <= $r; $x++) {
                $sum += $a[$row + max(0, min($w - 1, $x))];
            }
            for ($x = 0; $x < $w; $x++) {
                $tmp[$row + $x] = $sum;
                $sum += $a[$row + min($w - 1, $x + $r + 1)] - $a[$row + max(0, $x - $r)];
            }
        }
        $out = [];
        for ($x = 0; $x < $w; $x++) {
            $sum = 0.0;
            for ($y = -$r; $y <= $r; $y++) {
                $sum += $tmp[max(0, min($h - 1, $y)) * $w + $x];
            }
            for ($y = 0; $y < $h; $y++) {
                $out[$y * $w + $x] = $sum;
                $sum += $tmp[min($h - 1, $y + $r + 1) * $w + $x] - $tmp[max(0, $y - $r) * $w + $x];
            }
        }
        ksort($out);

        return $out;
    }
}
