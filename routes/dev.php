<?php

use App\Models\Frame;
use App\Support\CatalogAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Local development tools (only loaded when APP_ENV=local, staff only).
 */
Route::middleware(['web', 'auth'])->prefix('dev')->group(function () {
    // Renders realistic corner photos of every frame in the browser (three.js) and stores them.
    Route::get('frame-studio', function (Request $request) {
        abort_unless($request->user()?->isStaff(), 403);

        $frames = Frame::query()->orderBy('sort_order')->get()->map(fn (Frame $f) => [
            'id' => $f->id,
            'code' => $f->code,
            'name' => $f->name,
            'colour' => $f->colour_hex,
            'width_mm' => $f->profile_width_mm,
            'depth_mm' => $f->profile_depth_mm,
            'finish' => $f->finish->value,
            'material' => $f->material->getLabel(),
            'profile' => $f->profile?->value ?? 'flat',
            'upload_url' => url("dev/frame-studio/{$f->id}"),
        ]);

        return view('dev.frame-studio', ['frames' => $frames]);
    });

    // QA: saves a canvas capture (PNG data URL) to storage/app/qa/{name}.png for pixel-level review.
    Route::post('snapshot/{name}', function (Request $request, string $name) {
        abort_unless($request->user() !== null && preg_match('/^[a-z0-9-]{1,40}$/', $name), 403);
        $data = $request->validate(['image' => ['required', 'string', 'starts_with:data:image/png;base64,']]);
        $dir = storage_path('app/qa');
        is_dir($dir) || mkdir($dir, 0775, true);
        file_put_contents("{$dir}/{$name}.png", base64_decode(substr($data['image'], strlen('data:image/png;base64,'))));

        return response()->json(['ok' => true]);
    });

    Route::post('frame-studio/{frame}', function (Request $request, Frame $frame) {
        abort_unless($request->user()?->isStaff(), 403);
        $data = $request->validate(['image' => ['required', 'string', 'starts_with:data:image/jpeg;base64,']]);

        $path = CatalogAssets::path('frames', "{$frame->code}.jpg");
        file_put_contents($path, base64_decode(substr($data['image'], strlen('data:image/jpeg;base64,'))));
        CatalogAssets::attach($frame, 'corner', $path, replace: true);

        return response()->json(['ok' => true]);
    });
});
