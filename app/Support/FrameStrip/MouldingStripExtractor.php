<?php

namespace App\Support\FrameStrip;

use App\Enums\FramePhotoType;
use GdImage;

/**
 * Turns any photo of a frame (a corner close-up, the whole frame or a straight piece of
 * moulding) into a moulding strip the portal wraps around artworks:
 * horizontal, seamlessly tileable along X, top row = OUTER edge, bottom row = INNER
 * (sight) edge, 256–512 px tall and at least 2048 px long. Pure GD.
 */
final class MouldingStripExtractor
{
    public const MIN_HEIGHT = 256;

    public const MAX_HEIGHT = 512;

    public const MIN_LENGTH = 2048;

    private const ANALYSIS_MAX = 600;

    private const SOURCE_MAX = 3200;

    private const TEXTURE_MAX = 900;

    public function fromFile(string $path, FramePhotoType $type = FramePhotoType::Auto, bool $flip = false): MouldingStrip
    {
        $image = $this->load($path);
        $mask = Mask::of($image, self::ANALYSIS_MAX);

        // Plain backdrop: cut the frame out by colour. Busy backdrop (marble, desk, clutter):
        // find the arms by their parallel grain instead. Each falls back to the other.
        $methods = $mask->isClean() ? ['silhouette', 'texture'] : ['texture', 'silhouette'];
        $error = null;
        foreach ($methods as $method) {
            try {
                [$section, $detected] = $method === 'texture'
                    ? $this->texture($image, $type)
                    : $this->silhouette($image, $mask, $type);
                $error = null;
                break;
            } catch (StripException $e) {
                $error ??= $e;
            }
        }
        if ($error) {
            throw $error;
        }

        if ($flip) {
            imageflip($section, IMG_FLIP_VERTICAL);
        }

        return new MouldingStrip($this->tile($section), $detected);
    }

    /**
     * @return array{GdImage, FramePhotoType}
     */
    private function silhouette(GdImage $image, Mask $mask, FramePhotoType $type): array
    {
        $detected = $type === FramePhotoType::Auto ? $this->detect($mask) : $type;

        return [match ($detected) {
            FramePhotoType::Straight => $this->straight($image, $mask),
            FramePhotoType::Corner => $this->corner($image, $mask),
            default => $this->whole($image, $mask),
        }, $detected];
    }

    // ---- busy backgrounds: arms found by texture ------------------------------------

    /**
     * @return array{GdImage, FramePhotoType}
     */
    private function texture(GdImage $image, FramePhotoType $type): array
    {
        if ($type === FramePhotoType::Whole) {
            throw new StripException('Could not find the frame against this background. Use a plain backdrop, or a corner photo.');
        }

        $arms = TextureArms::of($image, self::TEXTURE_MAX);
        $candidates = [];
        foreach ($arms->directions() as $theta) {
            foreach (array_slice($arms->bands($theta), 0, 3) as $band) {
                $candidates[] = $band;
            }
        }
        if ($candidates === []) {
            throw new StripException('Could not find the moulding in the photo. Use a closer, sharper photo.');
        }

        // A corner: two arms in different directions whose ends meet.
        $pair = null;
        $diag = hypot($arms->w, $arms->h);
        foreach ($candidates as $i => $a) {
            foreach ($candidates as $j => $b) {
                if ($j <= $i) {
                    continue;
                }
                $angle = abs($a['theta'] - $b['theta']);
                $angle = min($angle, 180 - $angle);
                if ($angle < 35) {
                    continue;
                }
                $gap = INF;
                foreach ($this->armEnds($a) as $pa) {
                    foreach ($this->armEnds($b) as $pb) {
                        $gap = min($gap, hypot($pa[0] - $pb[0], $pa[1] - $pb[1]));
                    }
                }
                $reach = ($a['v1'] - $a['v0']) + ($b['v1'] - $b['v0']) + 0.05 * $diag;
                if ($gap <= $reach && ($pair === null || $pair[2] < $a['score'] + $b['score'])) {
                    $pair = [$a, $b, $a['score'] + $b['score']];
                }
            }
        }

        if ($type !== FramePhotoType::Straight && $pair !== null) {
            [$a, $b] = $pair;
            // Use the longer arm; the other one lies on its inner side.
            [$arm, $other] = ($a['u1'] - $a['u0']) >= ($b['u1'] - $b['u0']) ? [$a, $b] : [$b, $a];
            [$ox, $oy] = TextureArms::point($other, ($other['u0'] + $other['u1']) / 2, ($other['v0'] + $other['v1']) / 2);
            $t = deg2rad($arm['theta']);
            $otherV = -$ox * sin($t) + $oy * cos($t);
            $innerBelow = $otherV > ($arm['v0'] + $arm['v1']) / 2;

            $section = $this->armSection($image, $arms, $arm);
            if (! $innerBelow) {
                imageflip($section, IMG_FLIP_VERTICAL);
            }

            return [$section, FramePhotoType::Corner];
        }

        usort($candidates, fn ($x, $y) => $y['score'] <=> $x['score']);
        $section = $this->armSection($image, $arms, $candidates[0]);
        $this->darkSideDown($section);

        return [$section, $type === FramePhotoType::Corner ? FramePhotoType::Corner : FramePhotoType::Straight];
    }

    /**
     * @param  array{theta: float, v0: float, v1: float, u0: float, u1: float, score: float}  $arm
     * @return list<array{float, float}>
     */
    private function armEnds(array $arm): array
    {
        $v = ($arm['v0'] + $arm['v1']) / 2;

        return [TextureArms::point($arm, $arm['u0'], $v), TextureArms::point($arm, $arm['u1'], $v)];
    }

    /**
     * Levels one arm, finds its true outer and inner edges (the texture band can miss a
     * smooth lip, e.g. a plain cream slip) and crops the clean middle of it.
     * Returned with the arm's v0 side on top.
     *
     * @param  array{theta: float, v0: float, v1: float, u0: float, u1: float, score: float}  $arm
     */
    private function armSection(GdImage $image, TextureArms $arms, array $arm): GdImage
    {
        $fill = imagecolorallocate($image, 0, 0, 0);
        $rotated = imagerotate($image, $arm['theta'], $fill);
        $cx = imagesx($rotated) / 2;
        $cy = imagesy($rotated) / 2;
        $k = 1 / $arms->scale;

        $V0 = $arm['v0'] * $k + $cy;
        $V1 = $arm['v1'] * $k + $cy;
        $U0 = $arm['u0'] * $k + $cx;
        $U1 = $arm['u1'] * $k + $cx;
        $bw = $V1 - $V0;
        $trim = max(0.12 * ($U1 - $U0), 1.0 * $bw);
        $ua = (int) round($U0 + $trim);
        $ub = (int) round($U1 - $trim);
        if ($ub - $ua < $bw * 0.6) {
            // Short arm: settle for less margin at the ends.
            $ua = (int) round($U0 + 0.15 * ($U1 - $U0));
            $ub = (int) round($U1 - 0.15 * ($U1 - $U0));
        }
        if ($ub - $ua < 8 || $bw < 4) {
            throw new StripException('The straight part of the moulding is too short. Use a photo showing more of its length.');
        }

        // 1. Rough edges of the band, from the whole arm's row profile.
        $from = max(0, (int) floor($V0 - 0.9 * $bw));
        $to = min(imagesy($rotated) - 1, (int) ceil($V1 + 0.9 * $bw));
        [$mean] = $this->rowProfile($rotated, $ua, $ub, $from, $to);
        $top = $this->outermostEdge($mean, $V0 - 0.15 * $bw, $V0 + 0.3 * $bw, -1);
        $bottom = $this->outermostEdge($mean, $V1 - 0.3 * $bw, $V1 + 0.15 * $bw, 1);

        // 2. Straighten: perspective and a slightly-off angle make the edges converge or tilt.
        $margin = (int) round(0.9 * $bw);
        [$rectified, $top, $bottom] = $this->rectify($rotated, $ua, $ub, $top, $bottom, $margin);

        // 3. Precise edges plus any smooth lip (e.g. a plain cream slip) beyond them.
        [$mean, $rough] = $this->rowProfile($rectified, 0, imagesx($rectified) - 1, 0, imagesy($rectified) - 1);
        $bw = $bottom - $top;
        $top = $this->outermostEdge($mean, $top - 0.06 * $bw, $top + 0.06 * $bw, -1);
        $bottom = $this->outermostEdge($mean, $bottom - 0.06 * $bw, $bottom + 0.06 * $bw, 1);
        $bandRough = Mask::median(array_slice($rough, $top, max(1, $bottom - $top)));
        $outerY = $this->lip($mean, $rough, $top, -1, $bottom - $top, $bandRough);
        $innerY = $this->lip($mean, $rough, $bottom, 1, $bottom - $top, $bandRough);

        // Pull in a pixel or two so no background fringe survives.
        $pad = max(1, (int) round(($innerY - $outerY) * 0.015));
        $y0 = $outerY + $pad;
        $y1 = $innerY - $pad;
        if ($y1 - $y0 < 6) {
            throw new StripException('The moulding in the photo is too small. Use a closer, sharper photo.');
        }

        $crop = imagecrop($rectified, ['x' => 0, 'y' => $y0, 'width' => imagesx($rectified), 'height' => $y1 - $y0 + 1]);
        if (! $crop) {
            throw new StripException('Could not crop the moulding out of the photo.');
        }

        return $crop;
    }

    /**
     * Mean colour and along-the-row roughness (luminance std) of each row in [from, to].
     *
     * @return array{array<int, array{float, float, float}>, array<int, float>}
     */
    private function rowProfile(GdImage $img, int $x0, int $x1, int $from, int $to): array
    {
        $x0 = max(0, $x0);
        $x1 = min(imagesx($img) - 1, $x1);
        $step = max(1, intdiv($x1 - $x0, 300));
        $mean = $rough = [];
        for ($y = max(0, $from); $y <= min(imagesy($img) - 1, $to); $y++) {
            $r = $g = $b = 0;
            $lums = [];
            for ($x = $x0; $x <= $x1; $x += $step) {
                $c = imagecolorat($img, $x, $y);
                $cr = ($c >> 16) & 0xFF;
                $cg = ($c >> 8) & 0xFF;
                $cb = $c & 0xFF;
                $r += $cr;
                $g += $cg;
                $b += $cb;
                $lums[] = 0.299 * $cr + 0.587 * $cg + 0.114 * $cb;
            }
            $n = max(1, count($lums));
            $mean[$y] = [$r / $n, $g / $n, $b / $n];
            $mu = array_sum($lums) / $n;
            $var = 0.0;
            foreach ($lums as $l) {
                $var += ($l - $mu) ** 2;
            }
            $rough[$y] = sqrt($var / $n);
        }

        return [$mean, $rough];
    }

    /**
     * @param  array<int, array{float, float, float}>  $mean
     */
    private function edgeAt(array $mean, int $y, int $d = 2): float
    {
        return isset($mean[$y - $d], $mean[$y + $d]) ? $this->distance($mean[$y - $d], $mean[$y + $d]) : 0.0;
    }

    /**
     * The outermost (towards $dir) edge in [a, b] at least half as strong as the strongest.
     *
     * @param  array<int, array{float, float, float}>  $mean
     */
    private function outermostEdge(array $mean, float $a, float $b, int $dir): int
    {
        $strength = [];
        for ($y = (int) ceil(min($a, $b)); $y <= (int) floor(max($a, $b)); $y++) {
            $strength[$y] = $this->edgeAt($mean, $y);
        }
        if ($strength === [] || max($strength) <= 0) {
            return (int) round(($a + $b) / 2);
        }
        $max = max($strength);
        $ys = array_keys($strength);
        $dir < 0 ? sort($ys) : rsort($ys);
        foreach ($ys as $y) {
            if ($strength[$y] >= 0.5 * $max) {
                // Climb to this edge's own peak.
                while (isset($strength[$y - $dir]) && $strength[$y - $dir] > $strength[$y]) {
                    $y -= $dir;
                }

                return $y;
            }
        }

        return (int) array_search($max, $strength, true);
    }

    /**
     * Fits straight lines to the outer and inner edges chunk by chunk and resamples every
     * column so both become horizontal. Returns just the arm (with $margin rows of context
     * either side) and the new edge rows.
     *
     * @return array{GdImage, int, int}
     */
    private function rectify(GdImage $img, int $ua, int $ub, int $top, int $bottom, int $margin): array
    {
        $width = $ub - $ua + 1;
        $chunks = max(4, min(30, intdiv($width, 40)));
        $win = max(3, (int) round(($bottom - $top) * 0.08));
        $tops = $bottoms = [];
        for ($c = 0; $c < $chunks; $c++) {
            $cx0 = $ua + intdiv($c * $width, $chunks);
            $cx1 = $ua + intdiv(($c + 1) * $width, $chunks) - 1;
            [$mean] = $this->rowProfile($img, $cx0, $cx1, $top - $win - 3, $bottom + $win + 3);
            $xc = ($cx0 + $cx1) / 2 - $ua;
            $tops[] = [$xc, $this->strongestEdge($mean, $top - $win, $top + $win)];
            $bottoms[] = [$xc, $this->strongestEdge($mean, $bottom - $win, $bottom + $win)];
        }
        [$ta, $tb] = $this->fitLine($tops);
        [$ba, $bb] = $this->fitLine($bottoms);

        $T = (int) round($ta + $tb * $width / 2);
        $B = (int) round($ba + $bb * $width / 2);
        if ($B - $T < 4) {
            [$T, $B, $ta, $tb, $ba, $bb] = [$top, $bottom, (float) $top, 0.0, (float) $bottom, 0.0];
        }
        $outH = ($B - $T) + 2 * $margin;
        $out = imagecreatetruecolor($width, $outH);
        $maxY = imagesy($img) - 1;
        for ($x = 0; $x < $width; $x++) {
            $t = $ta + $tb * $x;
            $s = ($ba + $bb * $x - $t) / ($B - $T);
            $sx = min(imagesx($img) - 1, $ua + $x);
            // Sub-pixel (linear) resampling down the column: no jagged steps between columns.
            for ($y = 0; $y < $outH; $y++) {
                $sy = max(0.0, min((float) $maxY, $t + ($y - $margin) * $s));
                $y0 = (int) floor($sy);
                $f = $sy - $y0;
                $c0 = imagecolorat($img, $sx, $y0);
                $c1 = imagecolorat($img, $sx, min($maxY, $y0 + 1));
                $r = (int) round((($c0 >> 16) & 0xFF) * (1 - $f) + (($c1 >> 16) & 0xFF) * $f);
                $g = (int) round((($c0 >> 8) & 0xFF) * (1 - $f) + (($c1 >> 8) & 0xFF) * $f);
                $b = (int) round(($c0 & 0xFF) * (1 - $f) + ($c1 & 0xFF) * $f);
                imagesetpixel($out, $x, $y, ($r << 16) | ($g << 8) | $b);
            }
        }

        return [$out, $margin, $margin + ($B - $T)];
    }

    /**
     * @param  array<int, array{float, float, float}>  $mean
     */
    private function strongestEdge(array $mean, int $a, int $b): float
    {
        $best = ($a + $b) / 2;
        $bestE = -1.0;
        for ($y = $a; $y <= $b; $y++) {
            if (($e = $this->edgeAt($mean, $y)) > $bestE) {
                [$best, $bestE] = [$y, $e];
            }
        }

        return (float) $best;
    }

    /**
     * Least-squares line y = a + b·x, refitted once without outliers.
     *
     * @param  list<array{float, float}>  $points
     * @return array{float, float}
     */
    private function fitLine(array $points): array
    {
        $fit = function (array $pts): array {
            $n = count($pts);
            $sx = $sy = $sxx = $sxy = 0.0;
            foreach ($pts as [$x, $y]) {
                $sx += $x;
                $sy += $y;
                $sxx += $x * $x;
                $sxy += $x * $y;
            }
            $den = $n * $sxx - $sx * $sx;
            $b = abs($den) < 1e-9 ? 0.0 : ($n * $sxy - $sx * $sy) / $den;

            return [($sy - $b * $sx) / $n, $b];
        };
        [$a, $b] = $fit($points);
        $res = array_map(fn ($p) => abs($p[1] - ($a + $b * $p[0])), $points);
        $limit = max(2.0, 2.5 * Mask::median($res));
        $kept = array_values(array_filter($points, fn ($p) => abs($p[1] - ($a + $b * $p[0])) <= $limit));

        return count($kept) >= 3 ? $fit($kept) : [$a, $b];
    }

    /**
     * Extends an edge outwards over a smooth lip (plain slip, gilt bead): rows of one even
     * colour ending at a crisp step. A gradual shadow or the backdrop is not a lip.
     *
     * @param  array<int, array{float, float, float}>  $mean
     * @param  array<int, float>  $rough
     */
    private function lip(array $mean, array $rough, int $edge, int $dir, int $bw, float $bandRough): int
    {
        // Step past the edge's own blur first.
        $peak = $this->edgeAt($mean, $edge);
        $s = 2;
        while ($s < 0.2 * $bw && $this->edgeAt($mean, $edge + $s * $dir) > 0.25 * $peak) {
            $s++;
        }
        // Walk out to the first crisp step that clearly beats the ripples inside the lip.
        $inside = [];
        $boundary = null;
        for ($i = $s; $i <= (int) round(0.5 * $bw); $i++) {
            $y = $edge + $i * $dir;
            if (! isset($mean[$y + 3 * $dir])) {
                break;
            }
            $e = $this->edgeAt($mean, $y);
            $isPeak = $e >= $this->edgeAt($mean, $y - $dir) && $e >= $this->edgeAt($mean, $y + $dir);
            if (count($inside) >= 4 && $isPeak && $e >= 18 && $e >= 3 * max(1.0, Mask::median($inside))) {
                $boundary = $y;
                break;
            }
            $inside[] = $e;
        }
        if ($boundary === null) {
            return $edge;
        }
        $width = abs($boundary - $edge);
        if ($width < max(4, 0.03 * $bw)) {
            return $edge;
        }

        // A lip is smoother along its length than the backdrop (marble, wood grain, cloth).
        $rows = [];
        for ($i = $s; $i < $width - 2; $i++) {
            $rows[] = $rough[$edge + $i * $dir];
        }
        $even = $rows !== [] && Mask::median($rows) <= max(6.0, 0.6 * $bandRough);

        return $even ? $boundary - $dir : $edge;
    }

    private function darkSideDown(GdImage $section): void
    {
        $h = imagesy($section);
        $band = max(1, (int) round($h * 0.12));
        if ($this->luminance($section, 0, $band) + 2 < $this->luminance($section, $h - $band, $h)) {
            imageflip($section, IMG_FLIP_VERTICAL);
        }
    }

    /**
     * Corner: one quadrant of the outline is empty (inside the L). Straight: long and thin.
     * Anything else is treated as a whole frame.
     */
    public function detect(Mask $mask): FramePhotoType
    {
        $q = $this->quadrantFills($mask);
        $sorted = $q;
        sort($sorted);
        $maxIndex = array_search(max($q), $q, true);
        $minIndex = array_search(min($q), $q, true);
        if ($sorted[0] < 0.3 * $sorted[3] && $sorted[1] > $sorted[0] * 1.8 + 0.05 && $minIndex === 3 - $maxIndex) {
            return FramePhotoType::Corner;
        }

        [, $elongation] = $mask->axis();

        return $elongation >= 3.0 ? FramePhotoType::Straight : FramePhotoType::Whole;
    }

    // ---- straight piece -------------------------------------------------------------

    private function straight(GdImage $image, Mask $mask): GdImage
    {
        [$angle] = $mask->axis();
        $degrees = rad2deg($angle);
        if (abs($degrees) > 0.5) {
            [$r, $g, $b] = $mask->bg;
            $image = imagerotate($image, $degrees, imagecolorallocate($image, $r, $g, $b));
            $mask = Mask::of($image, self::ANALYSIS_MAX, $mask->bg);
        }

        [$x0, $y0, $x1, $y1] = $mask->bbox();
        $len = $x1 - $x0 + 1;
        $cx0 = $x0 + (int) round($len * 0.2);
        $cx1 = $x1 - (int) round($len * 0.2);

        $rows = [];
        for ($y = $y0; $y <= $y1; $y++) {
            $rows[$y] = $mask->rowCount($y, $cx0, $cx1) > 0.5 * ($cx1 - $cx0 + 1);
        }
        [$r0, $r1] = $this->longestRun($rows) ?? throw new StripException('Could not find the moulding in the photo.');

        $cols = [];
        for ($x = $x0; $x <= $x1; $x++) {
            $cols[$x] = $mask->colCount($x, $r0, $r1) > 0.5 * ($r1 - $r0 + 1);
        }
        [$c0, $c1] = $this->longestRun($cols) ?? throw new StripException('Could not find the moulding in the photo.');

        // Ends may be cut or mitred: keep the clean middle.
        $trim = (int) round(max(($r1 - $r0 + 1) * 0.25, ($c1 - $c0 + 1) * 0.03));
        $section = $this->cropScaled($image, $mask->scale, $c0 + $trim, $r0, $c1 - $trim, $r1);

        // The rebate lip / sight edge is usually the darker side: put it at the bottom.
        $h = imagesy($section);
        $band = max(1, (int) round($h * 0.12));
        if ($this->luminance($section, 0, $band) + 2 < $this->luminance($section, $h - $band, $h)) {
            imageflip($section, IMG_FLIP_VERTICAL);
        }

        return $section;
    }

    // ---- corner close-up ------------------------------------------------------------

    private function corner(GdImage $image, Mask $mask): GdImage
    {
        [$x0, $y0, $x1, $y1] = $mask->bbox();
        $W = $x1 - $x0 + 1;
        $H = $y1 - $y0 + 1;
        $q = $this->quadrantFills($mask);
        $corner = array_search(max($q), $q, true); // 0 TL, 1 TR, 2 BL, 3 BR
        $top = $corner < 2;
        $left = $corner % 2 === 0;
        $xm = $x0 + intdiv($W, 2);
        $ym = $y0 + intdiv($H, 2);

        // Arm widths: away from the corner each column (row) only crosses one arm.
        $counts = [];
        foreach ($this->farRange($x0, $W, $left) as $x) {
            $counts[] = $top ? $mask->colCount($x, $y0, $ym) : $mask->colCount($x, $ym, $y1);
        }
        $wh = (int) round(Mask::median($counts));
        $counts = [];
        foreach ($this->farRange($y0, $H, $top) as $y) {
            $counts[] = $left ? $mask->rowCount($y, $x0, $xm) : $mask->rowCount($y, $xm, $x1);
        }
        $wv = (int) round(Mask::median($counts));
        if ($wh < 2 || $wv < 2) {
            throw new StripException('Could not find the two arms of the corner in the photo.');
        }

        if ($W >= $H) {
            // Horizontal arm, past the mitre.
            $ry0 = $top ? $y0 : $y1 - $wh + 1;
            $ry1 = $top ? $y0 + $wh - 1 : $y1;
            $rx0 = $left ? $x0 + $wv + (int) round($wh * 0.15) : $x0 + (int) round($wh * 0.3);
            $rx1 = $left ? $x1 - (int) round($wh * 0.3) : $x1 - $wv - (int) round($wh * 0.15);
            $section = $this->cropScaled($image, $mask->scale, $rx0, $ry0, $rx1, $ry1);
            if (! $top) {
                imageflip($section, IMG_FLIP_VERTICAL);
            }

            return $section;
        }

        // Vertical arm, past the mitre; turn it so its outer side is on top.
        $rx0 = $left ? $x0 : $x1 - $wv + 1;
        $rx1 = $left ? $x0 + $wv - 1 : $x1;
        $ry0 = $top ? $y0 + $wh + (int) round($wv * 0.15) : $y0 + (int) round($wv * 0.3);
        $ry1 = $top ? $y1 - (int) round($wv * 0.3) : $y1 - $wh - (int) round($wv * 0.15);
        $section = $this->cropScaled($image, $mask->scale, $rx0, $ry0, $rx1, $ry1);

        // imagerotate is anticlockwise: 270 brings the left side to the top, 90 the right side.
        return imagerotate($section, $left ? 270 : 90, 0);
    }

    // ---- whole frame ----------------------------------------------------------------

    private function whole(GdImage $image, Mask $mask): GdImage
    {
        [$x0, $y0, $x1, $y1] = $mask->bbox();
        $W = $x1 - $x0 + 1;
        $H = $y1 - $y0 + 1;
        $xm = $x0 + intdiv($W, 2);
        $ym = $y0 + intdiv($H, 2);

        // Walk out from the middle of the picture until each rail's inner edge.
        $inner = $mask->meanColour($x0 + (int) ($W * 0.35), $y0 + (int) ($H * 0.35), $x0 + (int) ($W * 0.65), $y0 + (int) ($H * 0.65));
        $rowSig = fn (int $y) => $mask->meanColour($x0 + (int) ($W * 0.25), $y, $x1 - (int) ($W * 0.25), $y);
        $colSig = fn (int $x) => $mask->meanColour($x, $y0 + (int) ($H * 0.25), $x, $y1 - (int) ($H * 0.25));

        $spread = 0.0;
        for ($y = $y0 + (int) ($H * 0.4); $y <= $y0 + (int) ($H * 0.6); $y++) {
            $spread = max($spread, $this->distance($rowSig($y), $inner));
        }
        for ($x = $x0 + (int) ($W * 0.4); $x <= $x0 + (int) ($W * 0.6); $x++) {
            $spread = max($spread, $this->distance($colSig($x), $inner));
        }
        $tol = max(18.0, $spread * 1.5 + 12);

        $maxD = (int) (min($W, $H) * 0.45);

        // All four rails share one profile, so their colours agree at the same distance from
        // the outer edge; the picture inside differs from side to side. The first offset where
        // they stop agreeing is the sight edge.
        $sides = [[], [], [], []];
        for ($k = 0; $k <= $maxD + 1; $k++) {
            $sides[0][$k] = $rowSig($y0 + $k);
            $sides[1][$k] = $rowSig($y1 - $k);
            $sides[2][$k] = $colSig($x0 + $k);
            $sides[3][$k] = $colSig($x1 - $k);
        }
        // Worst pairwise difference, allowing a one-pixel misalignment between sides.
        $agreement = function (int $k) use ($sides): float {
            $worst = 0.0;
            for ($i = 0; $i < 4; $i++) {
                for ($j = $i + 1; $j < 4; $j++) {
                    $best = INF;
                    foreach ([-1, 0, 1] as $s) {
                        if (isset($sides[$j][$k + $s])) {
                            $best = min($best, $this->distance($sides[$i][$k], $sides[$j][$k + $s]));
                        }
                    }
                    $worst = max($worst, $best);
                }
            }

            return $worst;
        };
        $baseline = [];
        for ($k = 1; $k <= max(2, (int) (min($W, $H) * 0.03)); $k++) {
            $baseline[] = $agreement($k);
        }
        $agreeTol = max(30.0, Mask::median($baseline) * 2.5);
        // Profile steps (bead → face) blur over a pixel or two; the picture keeps disagreeing.
        $needed = max(4, (int) round($maxD * 0.04));
        $d = null;
        $run = 0;
        for ($k = 2; $k <= $maxD; $k++) {
            $run = $agreement($k) > $agreeTol ? $run + 1 : 0;
            if ($run >= $needed) {
                $d = $k - $needed + 1;
                break;
            }
        }

        // A plain inside (empty frame, plain mount) agrees everywhere: walk out from the middle instead.
        if ($d === null) {
            $estimates = array_filter([
                $this->walk($ym, $y0, -1, $rowSig, $inner, $tol),
                $this->walk($ym, $y1, 1, $rowSig, $inner, $tol),
                $this->walk($xm, $x0, -1, $colSig, $inner, $tol),
                $this->walk($xm, $x1, 1, $colSig, $inner, $tol),
            ]);
            if ($estimates === []) {
                throw new StripException('Could not tell the frame apart from the picture inside it.');
            }
            $d = (int) round(Mask::median(array_values($estimates)));
        }
        $d = max(2, min($d, $maxD));

        // Middle of the top rail, clear of both mitres.
        $margin = (int) round($d * 1.15);

        return $this->cropScaled($image, $mask->scale, $x0 + $margin, $y0, $x1 - $margin, $y0 + $d - 1);
    }

    /**
     * Steps from the centre towards an outer edge; returns the rail's width (from that
     * edge to the first two consecutive lines that no longer look like the picture).
     *
     * @param  callable(int): array{float, float, float}  $sig
     * @param  array{float, float, float}  $inner
     */
    private function walk(int $from, int $edge, int $step, callable $sig, array $inner, float $tol): ?int
    {
        $prevFar = false;
        for ($i = $from; $step < 0 ? $i >= $edge : $i <= $edge; $i += $step) {
            $far = $this->distance($sig($i), $inner) > $tol;
            if ($far && $prevFar) {
                return abs($edge - ($i - $step));
            }
            $prevFar = $far;
        }

        return null;
    }

    // ---- making the strip -----------------------------------------------------------

    private function tile(GdImage $section): GdImage
    {
        $sw = imagesx($section);
        $sh = imagesy($section);
        if ($sh < 8 || $sw < 8) {
            throw new StripException('The moulding in the photo is too small. Use a closer, sharper photo.');
        }

        $h = max(self::MIN_HEIGHT, min(self::MAX_HEIGHT, $sh));
        $w = (int) round($sw * $h / $sh);
        if ($w < $h / 2) {
            throw new StripException('The straight part of the moulding is too short. Use a photo showing more of its length.');
        }

        $part = imagecreatetruecolor($w, $h);
        imagecopyresampled($part, $section, 0, 0, 0, 0, $w, $h, $sw, $sh);
        if ($h > $sh * 1.25) {
            // Gentle sharpen after upscaling.
            imageconvolution($part, [[-1, -1, -1], [-1, 16, -1], [-1, -1, -1]], 8, 0);
        }

        if ($w > self::MIN_LENGTH) {
            $crop = imagecrop($part, ['x' => intdiv($w - self::MIN_LENGTH, 2), 'y' => 0, 'width' => self::MIN_LENGTH, 'height' => $h]);
            $part = $crop ?: $part;
            $w = imagesx($part);
        }
        $this->flattenLighting($part);

        // Mirror-tile: A | A' | A | A' … joins (and the wrap-around) are seamless.
        $mirror = imagecreatetruecolor($w, $h);
        imagecopy($mirror, $part, 0, 0, 0, 0, $w, $h);
        imageflip($mirror, IMG_FLIP_HORIZONTAL);

        $period = 2 * $w;
        $n = (int) max(1, ceil(self::MIN_LENGTH / $period));
        $out = imagecreatetruecolor($period * $n, $h);
        for ($i = 0; $i < $n; $i++) {
            imagecopy($out, $part, $i * $period, 0, 0, 0, $w, $h);
            imagecopy($out, $mirror, $i * $period + $w, 0, 0, 0, $w, $h);
        }

        return $out;
    }

    /**
     * Evens out light fall-off along the length so repeats don't show bright/dark bands.
     */
    private function flattenLighting(GdImage $img): void
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $stepY = max(1, intdiv($h, 64));
        $col = [];
        for ($x = 0; $x < $w; $x++) {
            $sum = 0;
            $n = 0;
            for ($y = 0; $y < $h; $y += $stepY) {
                $c = imagecolorat($img, $x, $y);
                $sum += 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);
                $n++;
            }
            $col[$x] = $sum / $n;
        }

        $half = max(4, intdiv($w, 10));
        $prefix = [0.0];
        foreach ($col as $x => $v) {
            $prefix[$x + 1] = $prefix[$x] + $v;
        }
        $smooth = [];
        for ($x = 0; $x < $w; $x++) {
            $a = max(0, $x - $half);
            $b = min($w, $x + $half + 1);
            $smooth[$x] = ($prefix[$b] - $prefix[$a]) / ($b - $a);
        }
        $mean = array_sum($smooth) / $w;
        if ($mean < 1) {
            return;
        }

        $gains = [];
        $maxDev = 0.0;
        foreach ($smooth as $x => $v) {
            $gains[$x] = max(0.85, min(1.18, $mean / max(1.0, $v)));
            $maxDev = max($maxDev, abs($gains[$x] - 1));
        }
        if ($maxDev < 0.02) {
            return;
        }

        for ($x = 0; $x < $w; $x++) {
            $g = $gains[$x];
            if (abs($g - 1) < 0.005) {
                continue;
            }
            for ($y = 0; $y < $h; $y++) {
                $c = imagecolorat($img, $x, $y);
                $r = min(255, (int) round((($c >> 16) & 0xFF) * $g));
                $gg = min(255, (int) round((($c >> 8) & 0xFF) * $g));
                $b = min(255, (int) round(($c & 0xFF) * $g));
                imagesetpixel($img, $x, $y, ($r << 16) | ($gg << 8) | $b);
            }
        }
    }

    // ---- helpers --------------------------------------------------------------------

    private function load(string $path): GdImage
    {
        $data = @file_get_contents($path);
        $raw = $data !== false ? @imagecreatefromstring($data) : false;
        if (! $raw) {
            throw new StripException('The photo could not be read. Upload a JPEG, PNG or WebP image.');
        }

        // Flatten any transparency onto white.
        $W = imagesx($raw);
        $H = imagesy($raw);
        $s = min(1.0, self::SOURCE_MAX / max($W, $H));
        $w = max(1, (int) round($W * $s));
        $h = max(1, (int) round($H * $s));
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $raw, 0, 0, 0, 0, $w, $h, $W, $H);

        $orientation = function_exists('exif_read_data') ? (@exif_read_data($path)['Orientation'] ?? 1) : 1;

        return match ((int) $orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, 270, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    /**
     * Crops a rectangle given in analysis-mask pixels out of the full-size image, pulled
     * in by half an analysis pixel so no background fringe survives.
     */
    private function cropScaled(GdImage $image, float $scale, int $x0, int $y0, int $x1, int $y1): GdImage
    {
        $inset = (int) floor(0.5 / $scale);
        $fx0 = (int) ceil($x0 / $scale) + $inset;
        $fy0 = (int) ceil($y0 / $scale) + $inset;
        $fx1 = (int) floor(($x1 + 1) / $scale) - 1 - $inset;
        $fy1 = (int) floor(($y1 + 1) / $scale) - 1 - $inset;
        $fx0 = max(0, $fx0);
        $fy0 = max(0, $fy0);
        $fx1 = min(imagesx($image) - 1, $fx1);
        $fy1 = min(imagesy($image) - 1, $fy1);
        if ($fx1 - $fx0 < 4 || $fy1 - $fy0 < 4) {
            throw new StripException('The moulding in the photo is too small. Use a closer, sharper photo.');
        }

        $crop = imagecrop($image, ['x' => $fx0, 'y' => $fy0, 'width' => $fx1 - $fx0 + 1, 'height' => $fy1 - $fy0 + 1]);
        if (! $crop) {
            throw new StripException('Could not crop the moulding out of the photo.');
        }

        return $crop;
    }

    /**
     * Foreground fill of the bounding box's four quadrants: TL, TR, BL, BR.
     *
     * @return array{float, float, float, float}
     */
    private function quadrantFills(Mask $mask): array
    {
        [$x0, $y0, $x1, $y1] = $mask->bbox();
        $xm = $x0 + intdiv($x1 - $x0 + 1, 2);
        $ym = $y0 + intdiv($y1 - $y0 + 1, 2);
        $fill = function (int $ax, int $ay, int $bx, int $by) use ($mask): float {
            $n = 0;
            for ($y = $ay; $y <= $by; $y++) {
                $n += $mask->rowCount($y, $ax, $bx);
            }

            return $n / max(1, ($bx - $ax + 1) * ($by - $ay + 1));
        };

        return [
            $fill($x0, $y0, $xm - 1, $ym - 1),
            $fill($xm, $y0, $x1, $ym - 1),
            $fill($x0, $ym, $xm - 1, $y1),
            $fill($xm, $ym, $x1, $y1),
        ];
    }

    /**
     * Positions 55–90 % of the way from the corner side to the far side.
     *
     * @return list<int>
     */
    private function farRange(int $start, int $size, bool $cornerAtStart): array
    {
        $a = $cornerAtStart ? $start + (int) ($size * 0.55) : $start + (int) ($size * 0.10);
        $b = $cornerAtStart ? $start + (int) ($size * 0.90) : $start + (int) ($size * 0.45);

        return range($a, max($a, $b));
    }

    /**
     * @param  array<int, bool>  $flags
     * @return array{int, int}|null
     */
    private function longestRun(array $flags): ?array
    {
        $best = null;
        $start = null;
        $last = null;
        foreach ($flags as $i => $on) {
            if ($on) {
                $start ??= $i;
                $last = $i;
            }
            if ((! $on || $i === array_key_last($flags)) && $start !== null) {
                if ($best === null || $last - $start > $best[1] - $best[0]) {
                    $best = [$start, $last];
                }
                $start = null;
            }
        }

        return $best;
    }

    private function luminance(GdImage $img, int $y0, int $y1): float
    {
        $w = imagesx($img);
        $step = max(1, intdiv($w, 200));
        $sum = 0.0;
        $n = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = 0; $x < $w; $x += $step) {
                $c = imagecolorat($img, $x, $y);
                $sum += 0.299 * (($c >> 16) & 0xFF) + 0.587 * (($c >> 8) & 0xFF) + 0.114 * ($c & 0xFF);
                $n++;
            }
        }

        return $n ? $sum / $n : 0.0;
    }

    /**
     * @param  array{float, float, float}  $a
     * @param  array{float, float, float}  $b
     */
    private function distance(array $a, array $b): float
    {
        return sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2);
    }
}
