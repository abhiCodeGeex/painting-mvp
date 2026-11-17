<?php

namespace App\Filament\Resources\Sales\Enquiries\Pages;

use App\Enums\EnquiryStatus;
use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Models\Enquiry;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEnquiries extends ListRecords
{
    protected static string $resource = EnquiryResource::class;

    /**
     * Inbox-style tabs. Drafts are the customer's unfinished work and are kept apart.
     */
    public function getTabs(): array
    {
        $count = fn (array $statuses): int => Enquiry::whereIn('status', $statuses)->count();

        $open = [EnquiryStatus::Submitted, EnquiryStatus::UnderReview, EnquiryStatus::NeedsClarification];

        return [
            'open' => Tab::make('Open')
                ->icon('heroicon-o-inbox')
                ->badge($count($open) ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $open)),
            'new' => Tab::make('New')
                ->badge($count([EnquiryStatus::Submitted]) ?: null)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', EnquiryStatus::Submitted)),
            'mine' => Tab::make('Assigned to me')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('assigned_to', auth()->id())->whereIn('status', $open)),
            'waiting' => Tab::make('Waiting for customer')
                ->badge($count([EnquiryStatus::NeedsClarification]) ?: null)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', EnquiryStatus::NeedsClarification)),
            'all' => Tab::make('All submitted')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', EnquiryStatus::Draft)),
            'drafts' => Tab::make('Drafts')
                ->icon('heroicon-o-pencil-square')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', EnquiryStatus::Draft)),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'open';
    }
}
