<?php

namespace App\Http\Controllers\Portal;

use App\Enums\EnquiryStatus;
use App\Http\Presenters\EnquiryPresenter;
use App\Models\Enquiry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends PortalController
{
    public function __invoke(Request $request): Response
    {
        $customer = $this->customer($request);

        $enquiries = $customer->enquiries()
            ->withCount(['lines', 'messages as unread_count' => fn (Builder $q) => $q->where('from_staff', true)->whereNull('read_at')->where('kind', '!=', 'system')])
            ->with(['lines' => fn ($q) => $q->with(['artwork.media', 'media']), 'orders'])
            ->latest('updated_at')
            ->get();

        $needsReply = $enquiries->where('status', EnquiryStatus::NeedsClarification);

        return Inertia::render('dashboard', [
            'stats' => [
                'drafts' => $enquiries->where('status', EnquiryStatus::Draft)->count(),
                'in_review' => $enquiries->whereIn('status', [EnquiryStatus::Submitted, EnquiryStatus::UnderReview])->count(),
                'needs_reply' => $needsReply->count(),
                'total' => $enquiries->where('status', '!=', EnquiryStatus::Draft)->count(),
            ],
            'actionNeeded' => $needsReply->map(fn (Enquiry $e) => EnquiryPresenter::summary($e))->values(),
            'recent' => $enquiries->take(5)->map(fn (Enquiry $e) => EnquiryPresenter::summary($e))->values(),
            'hasAddress' => $customer->addresses()->exists(),
        ]);
    }
}
