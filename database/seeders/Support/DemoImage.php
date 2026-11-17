<?php

namespace Database\Seeders\Support;

/**
 * Generates simple abstract "paintings" as JPEG files with GD, so demo artworks have images
 * without shipping copyrighted pictures.
 */
class DemoImage
{
    /**
     * @param  list<string>  $palette  hex colours
     * @return string path to a temporary JPEG
     */
    public static function abstract(int $width, int $height, array $palette, int $seed, string $style = 'blobs'): string
    {
        mt_srand($seed);
        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, true);

        // Vertical gradient between the first two colours.
        [$r1, $g1, $b1] = self::rgb($palette[0]);
        [$r2, $g2, $b2] = self::rgb($palette[1] ?? $palette[0]);
        for ($y = 0; $y < $height; $y++) {
            $t = $y / max(1, $height - 1);
            $c = imagecolorallocate($img, (int) ($r1 + ($r2 - $r1) * $t), (int) ($g1 + ($g2 - $g1) * $t), (int) ($b1 + ($b2 - $b1) * $t));
            imageline($img, 0, $y, $width, $y, $c);
        }

        $shapes = $style === 'horizon' ? 6 : 14;
        for ($i = 0; $i < $shapes; $i++) {
            [$r, $g, $b] = self::rgb($palette[mt_rand(0, count($palette) - 1)]);
            $colour = imagecolorallocatealpha($img, $r, $g, $b, mt_rand(45, 90));

            if ($style === 'horizon') {
                // Layered hills.
                $base = (int) ($height * (0.45 + $i * 0.09));
                $points = [0, $height];
                for ($x = 0; $x <= $width; $x += (int) ($width / 8)) {
                    $points[] = $x;
                    $points[] = $base + mt_rand(-(int) ($height * 0.06), (int) ($height * 0.06));
                }
                $points[] = $width;
                $points[] = $height;
                imagefilledpolygon($img, $points, $colour);
            } elseif ($style === 'botanical') {
                $cx = (int) ($width / 2 + mt_rand(-$width / 6, $width / 6));
                $cy = (int) ($height / 2 + mt_rand(-$height / 6, $height / 6));
                $size = mt_rand((int) ($width * 0.15), (int) ($width * 0.35));
                imagefilledellipse($img, $cx + (int) ($size / 2 * cos($i)), $cy + (int) ($size / 2 * sin($i)), $size, (int) ($size / 2.2), $colour);
            } else {
                $size = mt_rand((int) ($width * 0.15), (int) ($width * 0.6));
                imagefilledellipse($img, mt_rand(0, $width), mt_rand(0, $height), $size, (int) ($size * mt_rand(70, 130) / 100), $colour);
            }
        }

        imagefilter($img, IMG_FILTER_SMOOTH, 6);

        $path = tempnam(sys_get_temp_dir(), 'demo-art-').'.jpg';
        imagejpeg($img, $path, 88);
        imagedestroy($img);

        return $path;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
