<?php

namespace App\Services\Marketplace;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class BrowserWorkerClient
{
    /** @param array<string, mixed> $request */
    public function observe(array $request): Response
    {
        return Http::acceptJson()->asJson()
            ->timeout(max(1, min(30, (int) config('marketplace_monitoring.browser_worker.timeout', 12))))
            ->post(rtrim((string) config('marketplace_monitoring.browser_worker.url'), '/').'/observe', $request);
    }
}
