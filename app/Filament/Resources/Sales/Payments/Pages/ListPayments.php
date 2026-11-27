<?php

namespace App\Filament\Resources\Sales\Payments\Pages;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Sales\Orders\Widgets\OrderMoneyOverview;
use App\Filament\Resources\Sales\Payments\PaymentResource;
use App\Models\Payment;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    /** Orders value, expenses, money received and profit, above the list. */
    protected function getHeaderWidgets(): array
    {
        return [OrderMoneyOverview::class];
    }

    /**
     * "To check" holds the payments customers say they made, waiting for staff to confirm them.
     */
    public function getTabs(): array
    {
        $count = fn (PaymentStatus $status): ?int => Payment::where('status', $status)->count() ?: null;

        return [
            'to_check' => Tab::make('To check')
                ->icon('heroicon-o-clock')
                ->badge($count(PaymentStatus::Submitted))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PaymentStatus::Submitted)),
            'due' => Tab::make('Due')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [PaymentStatus::Due, PaymentStatus::Rejected])),
            'received' => Tab::make('Received')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', PaymentStatus::Received)),
            'all' => Tab::make('All'),
        ];
    }

    /** Straight to the payments waiting for a check, when there are any. */
    public function getDefaultActiveTab(): string|int|null
    {
        return Payment::where('status', PaymentStatus::Submitted)->exists() ? 'to_check' : 'all';
    }
}
