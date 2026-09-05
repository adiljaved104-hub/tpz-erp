<?php

namespace App\Jobs;

use App\Models\QuotationEmailDelivery;
use App\Notifications\QuotationEmailNotification;
use App\Services\ActivityLogger;
use App\Services\Notifications\EmailConfigurationService;
use App\Services\Quotations\QuotationPdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SendQuotationEmailJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(EmailConfigurationService $email, QuotationPdfService $pdf, ActivityLogger $activity): void
    {
        $delivery = QuotationEmailDelivery::query()->with('quotation.items')->findOrFail($this->deliveryId);
        if ($delivery->status !== 'queued') {
            return;
        }
        try {
            if (! $email->apply()) {
                throw new \RuntimeException('Email delivery is disabled or not configured.');
            }
            $bytes = $pdf->render($delivery->quotation)->output();
            Notification::route('mail', $delivery->recipient_email)->notifyNow(new QuotationEmailNotification($delivery->quotation, $bytes));
            $delivery->forceFill(['status' => 'sent', 'sent_at' => now(), 'safe_error_code' => null, 'safe_error_message' => null])->save();
            $activity->log('quotation.email_sent', $delivery->requestedBy, $delivery->quotation, ['quotation_reference' => $delivery->quotation->reference, 'delivery_id' => $delivery->id, 'actor_id' => $delivery->requested_by_user_id]);
        } catch (Throwable $exception) {
            $delivery->forceFill(['status' => 'failed', 'failed_at' => now(), 'safe_error_code' => class_basename($exception), 'safe_error_message' => 'Quotation email delivery failed. Check Email Settings and retry safely.'])->save();
            $activity->log('quotation.email_failed', $delivery->requestedBy, $delivery->quotation, ['quotation_reference' => $delivery->quotation->reference, 'delivery_id' => $delivery->id, 'actor_id' => $delivery->requested_by_user_id]);
            throw $exception;
        }
    }
}
