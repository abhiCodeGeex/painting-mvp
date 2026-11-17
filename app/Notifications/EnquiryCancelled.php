<?php

namespace App\Notifications;

use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Models\Enquiry;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Bell notification for admins when a customer cancels a submitted enquiry.
 */
class EnquiryCancelled extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Enquiry $enquiry) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("Enquiry {$this->enquiry->reference} was cancelled by the customer")
            ->body($this->enquiry->cancelled_reason)
            ->icon('heroicon-o-x-circle')
            ->iconColor('gray')
            ->actions([
                Action::make('open')->label('Open')
                    ->url(EnquiryResource::getUrl('view', ['record' => $this->enquiry], panel: 'admin'))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
