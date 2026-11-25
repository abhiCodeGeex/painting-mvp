<?php

namespace App\Notifications;

use App\Enums\Role;
use App\Enums\StockStatus;
use App\Filament\Resources\Inventory\StockItems\StockItemResource;
use App\Models\StockItem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Tells admins an item just went low or out of stock (email + bell in the admin panel).
 * Sent once when the level gets worse, not on every change.
 */
class LowStockAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public string $itemName;

    public string $itemType;

    public string $quantity;

    public string $threshold;

    public function __construct(StockItem $item, public StockStatus $status)
    {
        // Snapshot the numbers at the moment it crossed, so the message stays true.
        $item->loadMissing('stockable');
        $this->itemName = $item->name();
        $this->itemType = $item->typeLabel();
        $this->quantity = $item->formattedQuantity();
        $this->threshold = StockItem::formatQuantity($item->low_stock_threshold, $item->unit);

        // Stock changes run in a transaction: only alert once the change is really saved.
        $this->afterCommit();
    }

    /**
     * Active admin users.
     *
     * @return Collection<int, User>
     */
    public static function recipients(): Collection
    {
        return User::staff()->where('is_active', true)->role(Role::SuperAdmin->value)->get();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->title()}: {$this->itemName}")
            ->line("{$this->itemType} “{$this->itemName}” is {$this->describe()}.")
            ->line("In stock: {$this->quantity} (alert level: under {$this->threshold}).")
            ->action('Open inventory', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("{$this->title()}: {$this->itemName}")
            ->body("{$this->itemType} — {$this->quantity} left (alert under {$this->threshold}).")
            ->icon($this->status->getIcon())
            ->iconColor($this->status->getColor())
            ->actions([Action::make('open')->label('Open inventory')->url($this->url())->markAsRead()])
            ->getDatabaseMessage();
    }

    private function title(): string
    {
        return $this->status === StockStatus::Out ? 'Out of stock' : 'Low stock';
    }

    private function describe(): string
    {
        return $this->status === StockStatus::Out ? 'out of stock' : 'running low';
    }

    private function url(): string
    {
        return StockItemResource::getUrl('index', ['filters' => ['status' => ['value' => $this->status->value]]], panel: 'admin');
    }
}
