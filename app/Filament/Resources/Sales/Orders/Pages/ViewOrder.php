<?php

namespace App\Filament\Resources\Sales\Orders\Pages;

use App\Domain\Orders\OrderService;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Filament\Resources\Sales\Orders\OrderResource;
use App\Filament\Support\MoneyInput;
use App\Models\Order;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * One order: change its status at any time, adjust the token while it is unpaid, and confirm payments.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string
    {
        return 'Order '.$this->order()->number;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('token')
                ->label('Change token amount')
                ->icon('heroicon-o-pencil-square')
                ->color('gray')
                ->visible(fn () => ! in_array($this->order()->tokenPayment?->status, [PaymentStatus::Received, PaymentStatus::Submitted], true))
                ->fillForm(fn () => ['token_amount' => $this->order()->token_amount])
                ->schema([
                    MoneyInput::make('token_amount')->label('Token amount')->required()
                        ->minValue(fn () => app(OrderService::class)->minimumToken($this->order()->grand_total) / 100)
                        ->maxValue(fn () => $this->order()->grand_total / 100)
                        ->helperText(fn () => 'At least '.Money::format(app(OrderService::class)->minimumToken($this->order()->grand_total))
                            .'. The rest of the '.Money::format($this->order()->grand_total).' is due on delivery.'),
                ])
                ->action(function (array $data): void {
                    app(OrderService::class)->setTokenAmount($this->order(), (int) $data['token_amount'], auth()->user());
                    $this->refreshOrder();
                    Notification::make()->success()->title('Token amount updated')->send();
                }),

            Action::make('status')
                ->label('Change status')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->fillForm(fn () => ['status' => $this->order()->status->value])
                ->modalDescription('You can set any status at any time. The customer sees it in their enquiry.')
                ->schema([
                    Select::make('status')->options(OrderStatus::class)->required()->native(false)->selectablePlaceholder(false),
                ])
                ->action(function (array $data): void {
                    // The select hands back the enum itself.
                    $status = $data['status'] instanceof OrderStatus ? $data['status'] : OrderStatus::from($data['status']);
                    app(OrderService::class)->setStatus($this->order(), $status, auth()->user());
                    $this->refreshOrder();
                    Notification::make()->success()->title('Status updated')->send();
                }),
        ];
    }

    private function order(): Order
    {
        /** @var Order */
        return $this->getRecord();
    }

    private function refreshOrder(): void
    {
        $this->record = $this->order()->fresh();
    }
}
