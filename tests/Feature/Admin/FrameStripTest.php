<?php

use App\Enums\FramePhotoType;
use App\Enums\Role;
use App\Filament\Resources\Framing\Frames\FrameResource;
use App\Http\Presenters\CatalogOptions;
use App\Models\Frame;
use App\Models\User;
use App\Support\FrameStrip\FrameStripBuilder;
use App\Support\FrameStrip\MouldingStrip;
use App\Support\FrameStrip\MouldingStripExtractor;
use Database\Seeders\MasterDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;

/*
 * Synthetic mouldings: a red bead on the STRIP_OUTER edge, light wood face with grain, and a dark
 * blue lip on the STRIP_INNER edge. The strip must come out with red on top and blue at the bottom.
 */
const STRIP_OUTER = [200, 40, 40];
const STRIP_FACE = [210, 170, 110];
const STRIP_INNER = [30, 30, 90];

/** Colour at $offset px from the outer edge of a $face px moulding, $along px along it. */
function mouldingColour(GdImage $img, int $offset, int $face, int $along): int
{
    $t = $offset / $face;
    $c = $t < 0.12 ? STRIP_OUTER : ($t >= 0.85 ? STRIP_INNER : STRIP_FACE);
    $grain = $c === STRIP_FACE ? (int) round(14 * sin($along / 7) + 6 * sin($offset / 3)) : 0;

    return imagecolorallocate($img, max(0, min(255, $c[0] + $grain)), max(0, min(255, $c[1] + $grain)), max(0, min(255, $c[2] + $grain)));
}

function stripCanvas(int $w, int $h): GdImage
{
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 245, 245, 242));

    return $img;
}

function stripSavePhoto(GdImage $img, string $name): string
{
    $path = sys_get_temp_dir()."/frame-strip-{$name}.png";
    imagepng($img, $path);

    return $path;
}

/** Straight piece, outer edge at the BOTTOM, tilted 10°: must be levelled and flipped. */
function stripStraightPhoto(): string
{
    $img = stripCanvas(1500, 520);
    [$x0, $x1, $y0, $face] = [100, 1400, 170, 180];
    for ($x = $x0; $x <= $x1; $x++) {
        for ($o = 0; $o < $face; $o++) {
            imagesetpixel($img, $x, $y0 + $face - 1 - $o, mouldingColour($img, $o, $face, $x));
        }
    }
    $img = imagerotate($img, 10, imagecolorallocate($img, 245, 245, 242));

    return stripSavePhoto($img, 'straight');
}

/** Corner at the top-right; the vertical (right) arm is the longer one, outer side on the right. */
function stripCornerPhoto(): string
{
    $img = stripCanvas(900, 1300);
    [$left, $right, $top, $bottom, $face] = [150, 750, 100, 1200, 150];
    for ($y = $top; $y <= $bottom; $y++) {
        for ($x = $left; $x <= $right; $x++) {
            $fromTop = $y - $top;
            $fromRight = $right - $x;
            if ($fromTop >= $face && $fromRight >= $face) {
                continue;
            }
            // Mitre: whichever edge is nearer owns the pixel.
            $colour = $fromTop <= $fromRight && $fromTop < $face
                ? mouldingColour($img, $fromTop, $face, $x)
                : mouldingColour($img, $fromRight, $face, $y);
            imagesetpixel($img, $x, $y, $colour);
        }
    }

    return stripSavePhoto($img, 'corner');
}

/** Whole frame on a wall with a colourful picture inside. */
function stripWholePhoto(bool $empty = false): string
{
    $img = stripCanvas(1400, 1100);
    [$x0, $y0, $x1, $y1, $face] = [150, 150, 1250, 950, 120];
    for ($y = $y0; $y <= $y1; $y++) {
        for ($x = $x0; $x <= $x1; $x++) {
            $offsets = ['t' => $y - $y0, 'b' => $y1 - $y, 'l' => $x - $x0, 'r' => $x1 - $x];
            $side = array_search(min($offsets), $offsets, true);
            $o = $offsets[$side];
            $colour = $o < $face
                ? mouldingColour($img, $o, $face, in_array($side, ['t', 'b'], true) ? $x : $y)
                : ($empty ? imagecolorallocate($img, 245, 245, 242) : imagecolorallocate($img, 60 + (int) (($x - $x0) / 8), 150, 120 + (int) (($y - $y0) / 10)));
            imagesetpixel($img, $x, $y, $colour);
        }
    }

    return stripSavePhoto($img, $empty ? 'whole-empty' : 'whole');
}

/** @return array{float, float, float} mean colour of rows [$from, $to) */
function stripBand(GdImage $img, int $from, int $to): array
{
    $sum = [0, 0, 0];
    $n = 0;
    for ($y = $from; $y < $to; $y++) {
        for ($x = 0; $x < imagesx($img); $x += 16) {
            $c = imagecolorat($img, $x, $y);
            $sum[0] += ($c >> 16) & 0xFF;
            $sum[1] += ($c >> 8) & 0xFF;
            $sum[2] += $c & 0xFF;
            $n++;
        }
    }

    return [$sum[0] / $n, $sum[1] / $n, $sum[2] / $n];
}

function expectValidStrip(MouldingStrip $strip): void
{
    $img = $strip->image;
    $w = imagesx($img);
    $h = imagesy($img);
    expect($h)->toBeGreaterThanOrEqual(MouldingStripExtractor::MIN_HEIGHT)->toBeLessThanOrEqual(MouldingStripExtractor::MAX_HEIGHT)
        ->and($w)->toBeGreaterThanOrEqual(MouldingStripExtractor::MIN_LENGTH)
        ->and($w)->toBeGreaterThan($h);

    $band = (int) round($h * 0.06);
    [$tr, , $tb] = stripBand($img, 1, $band);
    [$br, , $bb] = stripBand($img, $h - $band, $h - 1);
    [$mr, $mg] = stripBand($img, (int) ($h * 0.45), (int) ($h * 0.55));

    // Outer (red) bead on top, inner (blue) lip at the bottom, wood in between.
    expect($tr - $tb)->toBeGreaterThan(80)
        ->and($bb - $br)->toBeGreaterThan(25)
        ->and($mr)->toBeGreaterThan(150)
        ->and($mg)->toBeGreaterThan(120);

    // Tileable: the last column wraps onto the first.
    $diff = 0;
    for ($y = 0; $y < $h; $y += 4) {
        $a = imagecolorat($img, 0, $y);
        $b = imagecolorat($img, $w - 1, $y);
        $diff += abs((($a >> 16) & 0xFF) - (($b >> 16) & 0xFF));
    }
    expect($diff / ($h / 4))->toBeLessThan(3);
}

test('a straight piece of moulding becomes a level strip with the outer edge on top', function () {
    $strip = (new MouldingStripExtractor)->fromFile(stripStraightPhoto());

    expect($strip->detectedType)->toBe(FramePhotoType::Straight);
    expectValidStrip($strip);
});

test('a corner close-up uses its longer arm, turned so the outer edge is on top', function () {
    $strip = (new MouldingStripExtractor)->fromFile(stripCornerPhoto());

    expect($strip->detectedType)->toBe(FramePhotoType::Corner);
    expectValidStrip($strip);
});

test('a whole frame uses the middle of its top rail', function () {
    $strip = (new MouldingStripExtractor)->fromFile(stripWholePhoto());

    expect($strip->detectedType)->toBe(FramePhotoType::Whole);
    expectValidStrip($strip);
});

test('the admin flip swaps the outer and inner edges', function () {
    $strip = (new MouldingStripExtractor)->fromFile(stripCornerPhoto(), FramePhotoType::Corner, flip: true);
    $h = $strip->height();

    [$tr, , $tb] = stripBand($strip->image, 1, (int) ($h * 0.06));
    expect($tb - $tr)->toBeGreaterThan(25);
});

test('a frame photo builds a strip that the portal receives', function () {
    Storage::fake('public');
    $this->seed(MasterDataSeeder::class);
    $frame = Frame::where('code', 'FR-WAL-30')->firstOrFail();

    $frame->addMedia(stripWholePhoto())->preservingOriginal()->toMediaCollection('photo');
    $this->artisan('frames:build-strips', ['frames' => ['FR-WAL-30']])->assertSuccessful();

    $frame->refresh();
    $strip = $frame->getFirstMedia('strip');
    expect($strip)->not->toBeNull()
        ->and($strip->getCustomProperty('detected_type'))->toBe('whole')
        ->and($frame->strip_error)->toBeNull();

    $payload = collect(CatalogOptions::forEditor()['frames'])->firstWhere('id', $frame->id);
    expect($payload['strip_url'])->toBe($strip->getUrl())
        ->and($payload['strip_face_mm'])->toBe($frame->profile_width_mm);

    // Up to date: nothing rebuilt; flipping rebuilds; removing the photo removes the strip.
    expect(app(FrameStripBuilder::class)->build($frame))->toBe(FrameStripBuilder::UP_TO_DATE);
    $frame->update(['strip_flip' => true]);
    expect(app(FrameStripBuilder::class)->build($frame))->toBe(FrameStripBuilder::BUILT);
    $frame->clearMediaCollection('photo');
    expect(app(FrameStripBuilder::class)->build($frame))->toBe(FrameStripBuilder::REMOVED)
        ->and($frame->fresh()->getFirstMedia('strip'))->toBeNull();

    $other = collect(CatalogOptions::forEditor()['frames'])->firstWhere('id', '!=', $frame->id);
    expect($other['strip_url'])->toBeNull()->and($other['strip_face_mm'])->toBeNull();
});

test('the frame form offers the photo upload, photo type and flip', function () {
    Storage::fake('public');
    $this->seed(MasterDataSeeder::class);
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->staff()->create();
    $admin->assignRole(Role::SuperAdmin->value);
    $this->actingAs($admin);

    $frame = Frame::firstOrFail();
    $this->get(FrameResource::getUrl('edit', ['record' => $frame]))->assertOk()
        ->assertSee('Moulding strip (from a photo)')
        ->assertSee('Flip inside / outside edge');
});

test('an empty frame (wall showing through) also uses its top rail', function () {
    $strip = (new MouldingStripExtractor)->fromFile(stripWholePhoto(empty: true), FramePhotoType::Whole);

    expectValidStrip($strip);
});
