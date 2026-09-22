<?php

namespace App\Jobs;

use App\Models\MobileDevice;
use App\Models\User;
use App\Services\CompanyEmailPolicyService;
use App\Services\Mobile\NotificationTarget;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SendMobilePush implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $userId, public string $notificationId) {}

    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(): void
    {
        if (! config('mobile.push_enabled')) {
            return;
        }
        $user = User::query()->find($this->userId);
        if (! $user || ! app(CompanyEmailPolicyService::class)->allowsAuthentication($user)) {
            return;
        }
        $n = $user->notifications()->find($this->notificationId);
        if (! $n || $n->read_at !== null) {
            return;
        }
        if (app(NotificationTarget::class)->resolve($user, $n->data) === null) {
            return;
        }

        $title = trim((string) ($n->data['title'] ?? ''));
        $title = $title !== '' ? Str::limit($title, 120) : 'Tech Point Zone ERP';
        $messages = app(NotificationInboxService::class)->displayMessages(collect([$n]), $user);
        $body = trim((string) ($messages[(string) $n->id] ?? ''));
        $body = $body !== '' ? Str::limit($body, 240) : 'You have a new ERP notification.';

        MobileDevice::query()->where('user_id', $user->id)->whereNull('disabled_at')->with('accessToken')->chunkById(100, function ($devices) use ($title, $body) {
            foreach ($devices as $device) {
                $session = $device->accessToken;
                if (! $session || ($session->expires_at !== null && $session->expires_at->isPast())) {
                    continue;
                }
                $expiration = config('sanctum.expiration');
                if ($expiration && $session->created_at->lte(now()->subMinutes($expiration))) {
                    continue;
                }
                $http = Http::acceptJson()->timeout(15);
                if (config('mobile.expo_access_token')) {
                    $http = $http->withToken(config('mobile.expo_access_token'));
                }
                $response = $http->post('https://exp.host/--/api/v2/push/send', [
                    'to' => $device->expo_token, 'title' => $title, 'body' => $body,
                    'sound' => 'default', 'channelId' => 'erp-alerts', 'data' => ['notification_id' => $this->notificationId],
                ])->throw();
                $ticket = $response->json('data');
                if (($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    $device->disabled_at = now();
                    $device->save();
                } elseif (($ticket['status'] ?? null) === 'ok' && isset($ticket['id'])) {
                    CheckMobilePushReceipt::dispatch($device->id, $device->token_hash, $ticket['id'])->delay(now()->addMinutes(15));
                } elseif (($ticket['status'] ?? null) === 'error') {
                    throw new \RuntimeException('Expo rejected a push notification.');
                }
            }
        });
    }
}
