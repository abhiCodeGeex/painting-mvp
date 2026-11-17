<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Quotes\QuoteBuilder;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Enquiry;
use App\Models\Quote;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Quote PDFs: customers get the stored PDF of a sent quote; staff can also preview drafts.
 */
class QuoteController extends Controller
{
    public function pdf(Request $request, Enquiry $enquiry, Quote $quote): Response
    {
        Gate::authorize('view', $enquiry);
        abort_unless($quote->enquiry_id === $enquiry->id && $quote->sent_at, 404);

        if ($quote->status === QuoteStatus::Sent) {
            $quote->update(['status' => QuoteStatus::Viewed, 'viewed_at' => now()]);
        }

        return $this->stored($quote, $request->boolean('download'));
    }

    public function adminPdf(Request $request, Quote $quote, QuoteBuilder $builder): Response
    {
        abort_unless($request->user()?->isStaff(), 403);

        if ($quote->getFirstMedia('pdf')) {
            return $this->stored($quote, false);
        }

        // Draft: render a live preview.
        return response($builder->renderPdf($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str($quote->displayNumber())->slug().'-draft.pdf"',
        ]);
    }

    private function stored(Quote $quote, bool $download): Response
    {
        $media = $quote->getFirstMedia('pdf');
        abort_unless($media && is_file($media->getPath()), 404);

        return response()->file($media->getPath(), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$media->file_name.'"',
        ]);
    }
}
