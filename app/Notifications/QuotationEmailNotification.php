<?php

namespace App\Notifications;

use App\Models\Quotation;
use App\Services\Branding\ApplicationBranding;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class QuotationEmailNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Quotation $quotation, private readonly string $pdf) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $type = $this->quotation->document_type->label();

        return app(ApplicationBranding::class)->mail(new MailMessage, "{$type} {$this->quotation->reference}")
            ->greeting("Dear {$this->quotation->customer_name},")
            ->line("Please find attached {$this->quotation->reference}.")
            ->line('Total: AED '.number_format((float) $this->quotation->grand_total, 2))
            ->line('Valid until: '.$this->quotation->valid_until->format('d M Y'))
            ->attachData($this->pdf, $this->quotation->reference.'.pdf', ['mime' => 'application/pdf']);
    }
}
