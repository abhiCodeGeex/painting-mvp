<?php

namespace App\Notifications;

use App\Models\Quote;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails the customer their quotation with the PDF attached.
 */
class QuoteSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Quote $quote, public string $body) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Your quotation {$this->quote->displayNumber()} – ".Money::format($this->quote->grand_total))
            ->greeting("Hello {$notifiable->name},");

        foreach (preg_split("/\n{2,}/", $this->body) as $paragraph) {
            // The link is offered as a button below instead.
            $paragraph = trim(preg_replace('/You can view and download it here:.*$/m', '', $paragraph));
            if ($paragraph !== '' && ! str_starts_with($paragraph, 'Hello ')) {
                $mail->line($paragraph);
            }
        }

        $mail->action('View quotation', route('portal.enquiries.show', $this->quote->enquiry_id))
            ->line('The quotation is attached as a PDF.');

        if ($pdf = $this->quote->getFirstMedia('pdf')) {
            $mail->attach($pdf->getPath(), ['as' => $pdf->file_name, 'mime' => 'application/pdf']);
        }

        return $mail;
    }
}
