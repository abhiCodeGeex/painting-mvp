<?php

namespace App\Notifications;

use App\Filament\Resources\Sales\Enquiries\EnquiryResource;
use App\Models\Quote;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The customer approved / asked for changes on / rejected a quotation (spec §9, §11).
 * Staff get mail + an admin bell notification; the customer gets an approval confirmation.
 */
class QuoteResponded extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param 'approved'|'changes_requested'|'rejected'|'requote' $action */
    public function __construct(public Quote $quote, public string $action) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $notifiable->isStaff() ? ['mail', 'database'] : ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $quote = $this->quote;
        $number = $quote->displayNumber();
        $mail = (new MailMessage)->greeting("Hello {$notifiable->name},");

        if (! $notifiable->isStaff()) {
            return $mail->subject("Thank you – quotation {$number} approved")
                ->line("We have received your approval of quotation {$number} for ".Money::format($quote->grand_total).'.')
                ->line('Our team will be in touch shortly about the next steps.')
                ->action('View your enquiry', route('portal.enquiries.show', $quote->enquiry_id));
        }

        $mail->subject($this->title())
            ->line($this->title().'.');
        if ($comment = $quote->response['comment'] ?? null) {
            $mail->line('“'.Str::limit($comment, 600).'”');
        }

        return $mail->action('Open enquiry', EnquiryResource::getUrl('view', ['record' => $quote->enquiry], panel: 'admin'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body(Str::limit((string) ($this->quote->response['comment'] ?? $this->quote->response['reason'] ?? ''), 120))
            ->icon(match ($this->action) {
                'approved' => 'heroicon-o-check-badge',
                'rejected' => 'heroicon-o-x-circle',
                default => 'heroicon-o-arrow-path',
            })
            ->iconColor(match ($this->action) {
                'approved' => 'success',
                'rejected' => 'danger',
                default => 'warning',
            })
            ->actions([
                Action::make('open')->label('Open')
                    ->url(EnquiryResource::getUrl('view', ['record' => $this->quote->enquiry], panel: 'admin'))
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    private function title(): string
    {
        $who = $this->quote->enquiry->customer->displayName();
        $number = $this->quote->displayNumber();

        return match ($this->action) {
            'approved' => "{$who} approved quotation {$number}",
            'changes_requested' => "{$who} requested changes on {$number}",
            'rejected' => "{$who} declined quotation {$number}",
            default => "{$who} asked for a re-quote of {$number}",
        };
    }
}
