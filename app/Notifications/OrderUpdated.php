<?php

namespace App\Notifications;

use App\Filament\Resources\Sales\Orders\OrderResource;
use App\Models\Order;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Something happened on an order. Staff get mail + an admin bell notification (order placed, payment
 * sent by the customer); the customer gets mail (payment confirmed or not found, status changed).
 */
class OrderUpdated extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  'placed'|'payment_submitted'|'payment_received'|'payment_not_received'|'status'  $event
     * @param  string  $summary  one plain sentence, also posted in the enquiry thread
     */
    public function __construct(public Order $order, public string $event, public string $summary) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->isStaff() ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->summary);

        return $notifiable->isStaff()
            ? $mail->action('Open order', OrderResource::getUrl('view', ['record' => $this->order], panel: 'admin'))
            : $mail->action('View your order', route('portal.enquiries.show', $this->order->enquiry_id).'#order');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->summary)
            ->icon($this->event === 'placed' ? 'heroicon-o-shopping-bag' : 'heroicon-o-banknotes')
            ->iconColor($this->event === 'payment_submitted' ? 'warning' : 'success')
            ->actions([
                Action::make('open')->label('Open order')
                    ->url(OrderResource::getUrl('view', ['record' => $this->order], panel: 'admin'))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    private function title(): string
    {
        $number = $this->order->number;

        return match ($this->event) {
            'placed' => "New order {$number}",
            'payment_submitted' => "Payment to check – order {$number}",
            'payment_received' => "Payment received – order {$number}",
            'payment_not_received' => "Please check your payment – order {$number}",
            default => "Order {$number}: {$this->order->status->customerLabel()}",
        };
    }
}
