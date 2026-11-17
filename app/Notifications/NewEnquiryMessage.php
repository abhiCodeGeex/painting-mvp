<?php

namespace App\Notifications;

use App\Enums\MessageKind;
use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Models\Message;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A new message (or question) in an enquiry's conversation, sent to the other side.
 */
class NewEnquiryMessage extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Message $message) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->isStaff() ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $enquiry = $this->message->enquiry;
        $isQuestion = $this->message->kind === MessageKind::Question;
        $author = $this->message->author?->name ?? 'Our team';

        $mail = (new MailMessage)
            ->subject(($isQuestion ? 'Question about' : 'New message on')." enquiry {$enquiry->reference}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$author} wrote:")
            ->line('“'.Str::limit($this->message->body, 600).'”');

        if ($notifiable->isStaff()) {
            return $mail->action('Open enquiry', EnquiryResource::getUrl('view', ['record' => $enquiry], panel: 'admin'));
        }

        return $mail
            ->action($isQuestion ? 'Reply' : 'View conversation', route('portal.enquiries.show', $enquiry).'#messages')
            ->line($isQuestion ? 'We need your answer to continue preparing your quotation.' : '');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $enquiry = $this->message->enquiry;

        return FilamentNotification::make()
            ->title("{$enquiry->customer->displayName()} replied on {$enquiry->reference}")
            ->body(Str::limit($this->message->body, 120))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->iconColor('warning')
            ->actions([
                Action::make('open')->label('Open')
                    ->url(EnquiryResource::getUrl('view', ['record' => $enquiry], panel: 'admin'))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }
}
