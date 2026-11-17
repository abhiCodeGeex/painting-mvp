<?php

namespace App\Http\Controllers;

use App\Models\Enquiry;
use App\Models\EnquiryLine;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves customer uploads (stored on the private "local" disk) only to staff and to the
 * customer who owns the enquiry.
 */
class PrivateMediaController extends Controller
{
    public function __invoke(Request $request, Media $media, ?string $conversion = null): BinaryFileResponse
    {
        $enquiry = match (true) {
            $media->model instanceof EnquiryLine => $media->model->enquiry,
            $media->model instanceof Message => $media->model->enquiry,
            $media->model instanceof Enquiry => $media->model,
            default => null,
        };

        abort_unless($enquiry && Gate::forUser($request->user())->allows('view', $enquiry), 403);

        $useConversion = $conversion && $media->hasGeneratedConversion($conversion);
        $path = $useConversion ? $media->getPath($conversion) : $media->getPath();

        abort_unless(is_file($path), 404);

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response()->file($path, [
            'Content-Type' => $useConversion ? 'image/jpeg' : $media->mime_type,
            'Content-Disposition' => $disposition.'; filename="'.addslashes($media->file_name).'"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
