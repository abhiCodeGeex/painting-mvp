<?php

namespace App\Notifications;

use App\Models\Enquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmation to the customer that their enquiry was received.
 */
class EnquirySubmitted extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Enquiry $enquiry) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lines = $this->enquiry->lines()->count();

        return (new MailMessage)
            ->subject("We received your enquiry {$this->enquiry->reference}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Thank you for your enquiry \"{$this->enquiry->project_name}\" ({$lines} ".str('artwork')->plural($lines).').')
            ->line('Our team will review it and get back to you, usually within one working day.')
            ->action('View your enquiry', route('portal.enquiries.show', $this->enquiry))
            ->line('You can reply to our questions and follow progress from your account.');
    }
}
