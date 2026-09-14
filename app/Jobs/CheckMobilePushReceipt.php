<?php

namespace App\Jobs;

use App\Models\MobileDevice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class CheckMobilePushReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $deviceId, public string $tokenHash, public string $ticketId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        if (! config('mobile.push_enabled')) {
            return;
        }
        $device = MobileDevice::query()->whereKey($this->deviceId)->where('token_hash', $this->tokenHash)->whereNull('disabled_at')->first();
        if (! $device) {
            return;
        }
        $http = Http::acceptJson()->timeout(15);
        if (config('mobile.expo_access_token')) {
            $http = $http->withToken(config('mobile.expo_access_token'));
        }
        $result = $http->post('https://exp.host/--/api/v2/push/getReceipts', ['ids' => [$this->ticketId]])->throw()->json('data');
        $receipt = $result[$this->ticketId] ?? null;
        if ($receipt === null) {
            $this->release(300);

            return;
        }
        if (($receipt['details']['error'] ?? null) === 'DeviceNotRegistered') {
            $device->disabled_at = now();
            $device->save();
        }
    }
}
