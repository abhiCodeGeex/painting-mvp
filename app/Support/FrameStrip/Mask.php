<?php

namespace App\Support\FrameStrip;

use GdImage;

/**
 * A small analysis copy of a photo plus a foreground mask (anything that differs clearly
 * from the background colour sampled along the photo's border).
 */
final class Mask
{
    /** @var list<int> packed RGB per pixel of the analysis copy */
    public array $rgb = [];

    /** @var list<bool> */
    public array $on = [];

    public int $w;

    public int $h;

    /** analysis px per source px */
    public float $scale;

    /** @var array{int, int, int} */
    public array $bg;

    /** median colour distance of border pixels from the background colour */
    public float $noise = 0.0;

    /** share of pixels that differ from the background */
    public float $fraction = 0.0;

    /**
     * A plain, even backdrop with a frame on it — the colour silhouette can be trusted.
     */
    public function isClean(): bool
    {
        return $this->noise < 14 && $this->fraction > 0.02 && $this->fraction < 0.9;
    }

    /**
     * @param  array{int, int, int}|null  $bg  reuse a known background colour (e.g. after rotating)
     */
    public static function of(GdImage $image, int $max, ?array $bg = null): self
    {
        $mask = new self;
        $W = imagesx($image);
        $H = imagesy($image);
        $s = min(1.0, $max / max($W, $H));
        $mask->w = max(1, (int) round($W * $s));
        $mask->h = max(1, (int) round($H * $s));
        $mask->scale = $mask->w / $W;

        $small = imagecreatetruecolor($mask->w, $mask->h);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $mask->w, $mask->h, $W, $H);
        for ($y = 0; $y < $mask->h; $y++) {
            for ($x = 0; $x < $mask->w; $x++) {
                $mask->rgb[] = imagecolorat($small, $x, $y) & 0xFFFFFF;
            }
        }

        $mask->bg = $bg ?? $mask->borderColour();
        $mask->build();

        return $mask;
    }

    /**
     * Median colour of a thin band along the photo's edges.
     *
     * @return array{int, int, int}
     */
    private function borderColour(): array
    {
        $band = max(2, (int) round(min($this->w, $this->h) * 0.02));
        $r = $g = $b = [];
        for ($y = 0; $y < $this->h; $y++) {
            for ($x = 0; $x < $this->w; $x++) {
                if ($x >= $band && $x < $this->w - $band && $y >= $band && $y < $this->h - $band) {
                    $x = $this->w - $band - 1;

                    continue;
                }
                $c = $this->rgb[$y * $this->w + $x];
                $r[] = ($c >> 16) & 0xFF;
                $g[] = ($c >> 8) & 0xFF;
                $b[] = $c & 0xFF;
            }
        }

        return [self::median($r), self::median($g), self::median($b)];
    }

    private function build(): void
    {
        [$br, $bgG, $bb] = $this->bg;
        $dist = [];
        foreach ($this->rgb as $i => $c) {
            $dist[$i] = sqrt((((($c >> 16) & 0xFF) - $br) ** 2) + (((($c >> 8) & 0xFF) - $bgG) ** 2) + ((($c & 0xFF) - $bb) ** 2));
        }

        // Threshold from how noisy the background itself is.
        $band = max(2, (int) round(min($this->w, $this->h) * 0.02));
        $edge = [];
        for ($x = 0; $x < $this->w; $x++) {
            for ($y = 0; $y < $band; $y++) {
                $edge[] = $dist[$y * $this->w + $x];
                $edge[] = $dist[($this->h - 1 - $y) * $this->w + $x];
            }
        }
        $this->noise = self::median($edge);
        $threshold = max(28.0, min(90.0, $this->noise * 3 + 20));

        $raw = [];
        $count = 0;
        foreach ($dist as $i => $d) {
            $raw[$i] = $d > $threshold;
            $count += $raw[$i] ? 1 : 0;
        }

        $fraction = $this->fraction = $count / max(1, count($raw));
        if ($fraction > 0.97 || $fraction < 0.005) {
            // The photo is all frame (or the frame matches the background): use everything.
            $this->on = array_fill(0, count($raw), true);

            return;
        }

        // 3×3 majority filter drops speckles and fills pinholes.
        $on = [];
        for ($y = 0; $y < $this->h; $y++) {
            for ($x = 0; $x < $this->w; $x++) {
                $n = 0;
                $t = 0;
                for ($dy = -1; $dy <= 1; $dy++) {
                    $yy = $y + $dy;
                    if ($yy < 0 || $yy >= $this->h) {
                        continue;
                    }
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $xx = $x + $dx;
                        if ($xx < 0 || $xx >= $this->w) {
                            continue;
                        }
                        $t++;
                        $n += $raw[$yy * $this->w + $xx] ? 1 : 0;
                    }
                }
                $on[] = $n * 2 > $t;
            }
        }
        $this->on = $on;
    }

    public function at(int $x, int $y): bool
    {
        return $this->on[$y * $this->w + $x];
    }

    public function rowCount(int $y, int $x0, int $x1): int
    {
        $n = 0;
        $base = $y * $this->w;
        for ($x = max(0, $x0); $x <= min($this->w - 1, $x1); $x++) {
            $n += $this->on[$base + $x] ? 1 : 0;
        }

        return $n;
    }

    public function colCount(int $x, int $y0, int $y1): int
    {
        $n = 0;
        for ($y = max(0, $y0); $y <= min($this->h - 1, $y1); $y++) {
            $n += $this->on[$y * $this->w + $x] ? 1 : 0;
        }

        return $n;
    }

    /**
     * Bounding box of the foreground, ignoring rows/columns with only a few stray pixels.
     *
     * @return array{int, int, int, int} x0, y0, x1, y1 (inclusive)
     */
    public function bbox(): array
    {
        $minRow = max(2, (int) round($this->w * 0.02));
        $minCol = max(2, (int) round($this->h * 0.02));
        $y0 = 0;
        while ($y0 < $this->h - 1 && $this->rowCount($y0, 0, $this->w - 1) < $minRow) {
            $y0++;
        }
        $y1 = $this->h - 1;
        while ($y1 > $y0 && $this->rowCount($y1, 0, $this->w - 1) < $minRow) {
            $y1--;
        }
        $x0 = 0;
        while ($x0 < $this->w - 1 && $this->colCount($x0, $y0, $y1) < $minCol) {
            $x0++;
        }
        $x1 = $this->w - 1;
        while ($x1 > $x0 && $this->colCount($x1, $y0, $y1) < $minCol) {
            $x1--;
        }

        return [$x0, $y0, $x1, $y1];
    }

    /**
     * Orientation of the foreground's long axis (radians, y pointing down) and how elongated it is.
     *
     * @return array{float, float} angle, elongation (sqrt of eigenvalue ratio)
     */
    public function axis(): array
    {
        $n = $sx = $sy = $sxx = $syy = $sxy = 0.0;
        for ($y = 0; $y < $this->h; $y++) {
            for ($x = 0; $x < $this->w; $x++) {
                if ($this->on[$y * $this->w + $x]) {
                    $n++;
                    $sx += $x;
                    $sy += $y;
                    $sxx += $x * $x;
                    $syy += $y * $y;
                    $sxy += $x * $y;
                }
            }
        }
        if ($n < 10) {
            return [0.0, 1.0];
        }
        $mx = $sx / $n;
        $my = $sy / $n;
        $a = $sxx / $n - $mx * $mx;
        $c = $syy / $n - $my * $my;
        $b = $sxy / $n - $mx * $my;
        $angle = 0.5 * atan2(2 * $b, $a - $c);
        $root = sqrt((($a - $c) / 2) ** 2 + $b * $b);
        $l1 = ($a + $c) / 2 + $root;
        $l2 = max(1e-6, ($a + $c) / 2 - $root);

        return [$angle, sqrt($l1 / $l2)];
    }

    /**
     * Mean colour of the analysis copy over a rectangle (inclusive).
     *
     * @return array{float, float, float}
     */
    public function meanColour(int $x0, int $y0, int $x1, int $y1): array
    {
        $r = $g = $b = 0;
        $n = 0;
        for ($y = max(0, $y0); $y <= min($this->h - 1, $y1); $y++) {
            for ($x = max(0, $x0); $x <= min($this->w - 1, $x1); $x++) {
                $c = $this->rgb[$y * $this->w + $x];
                $r += ($c >> 16) & 0xFF;
                $g += ($c >> 8) & 0xFF;
                $b += $c & 0xFF;
                $n++;
            }
        }
        $n = max(1, $n);

        return [$r / $n, $g / $n, $b / $n];
    }

    /**
     * @param  array<int, int|float>  $values
     */
    public static function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $n = count($values);

        return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }
}
