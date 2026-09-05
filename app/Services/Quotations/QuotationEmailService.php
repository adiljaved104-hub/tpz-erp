<?php

namespace App\Services\Quotations;

use App\Enums\QuotationPermission;
use App\Enums\QuotationStatus;
use App\Jobs\SendQuotationEmailJob;
use App\Models\Quotation;
use App\Models\QuotationEmailDelivery;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Notifications\EmailConfigurationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuotationEmailService
{
    public function __construct(private readonly QuotationAuthorization $authorization, private readonly EmailConfigurationService $email, private readonly ActivityLogger $activity) {}

    public function queue(Quotation $quotation, string $recipient, User $actor, ?string $idempotencyKey = null): QuotationEmailDelivery
    {
        $this->authorization->authorize($actor, QuotationPermission::Send, $quotation);
        $recipient = validator(['email' => $recipient], ['email' => ['required', 'email', 'max:190']])->validate()['email'];
        if (! $this->email->enabled() || ! $this->email->configured()) {
            throw ValidationException::withMessages(['email' => 'Email delivery is disabled or not configured.']);
        }
        if (! in_array($quotation->effectiveStatus(), [QuotationStatus::Draft, QuotationStatus::Sent], true)) {
            throw ValidationException::withMessages(['status' => 'Only a Draft or Sent quotation can be emailed.']);
        }
        $key = $idempotencyKey ?: (string) Str::uuid();
        if ($existing = QuotationEmailDelivery::query()->where('idempotency_key', $key)->first()) {
            return $existing;
        }

        $delivery = DB::transaction(function () use ($quotation, $recipient, $actor, $key): QuotationEmailDelivery {
            $locked = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            $delivery = $locked->emailDeliveries()->create([
                'recipient_email' => strtolower(trim($recipient)), 'subject' => $locked->document_type->label().' '.$locked->reference,
                'status' => 'queued', 'requested_at' => now(), 'idempotency_key' => $key, 'requested_by_user_id' => $actor->id,
            ]);
            if ($locked->status === QuotationStatus::Draft) {
                $locked->forceFill(['status' => QuotationStatus::Sent, 'sent_at' => now()])->save();
            }
            $this->activity->log('quotation.email_queued', $actor, $locked, ['quotation_reference' => $locked->reference, 'delivery_id' => $delivery->id, 'actor_id' => $actor->id]);

            return $delivery;
        });
        SendQuotationEmailJob::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }
}
