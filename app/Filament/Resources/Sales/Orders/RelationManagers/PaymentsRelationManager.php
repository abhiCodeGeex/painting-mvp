<?php

namespace App\Filament\Resources\Sales\Orders\RelationManagers;

use App\Filament\Resources\Sales\Orders\OrderResource;
use App\Filament\Resources\Sales\Payments\PaymentResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/** The order's token and full & final payments, with confirm / not received actions. */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    protected static ?string $title = 'Payments';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns(PaymentResource::columns())
            ->paginated(false)
            // Reload so the order's status and balance above follow the payment.
            ->recordActions([
                PaymentResource::receiveAction()->after(fn () => $this->redirect(OrderResource::getUrl('view', ['record' => $this->getOwnerRecord()]))),
                PaymentResource::notReceivedAction()->after(fn () => $this->redirect(OrderResource::getUrl('view', ['record' => $this->getOwnerRecord()]))),
                PaymentResource::undoReceivedAction()->after(fn () => $this->redirect(OrderResource::getUrl('view', ['record' => $this->getOwnerRecord()]))),
            ])
            ->recordAction(null)
            ->emptyStateHeading('No payments');
    }
}
