<?php

namespace App\Notifications;

use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Models\Enquiry;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells admins a new enquiry arrived (email + bell in the admin panel).
 */
class EnquirySubmittedForStaff extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Enquiry $enquiry) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $customer = $this->enquiry->customer;

        return (new MailMessage)
            ->subject("New enquiry {$this->enquiry->reference} from {$customer->displayName()}")
            ->line("Project: {$this->enquiry->project_name}")
            ->line('Artworks: '.$this->enquiry->lines()->count())
            ->action('Open enquiry', $this->url());
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title("New enquiry {$this->enquiry->reference}")
            ->body($this->enquiry->customer->displayName().' — '.$this->enquiry->project_name)
            ->icon('heroicon-o-inbox-arrow-down')
            ->iconColor('info')
            ->actions([Action::make('open')->label('Open')->url($this->url())->markAsRead()])
            ->getDatabaseMessage();
    }

    private function url(): string
    {
        return EnquiryResource::getUrl('view', ['record' => $this->enquiry], panel: 'admin');
    }
}
