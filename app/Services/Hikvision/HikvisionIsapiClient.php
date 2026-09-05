<?php

namespace App\Services\Hikvision;
use Illuminate\Support\Facades\Log;
use App\DTOs\Hikvision\HikvisionConnectionResult;
use App\DTOs\Hikvision\HikvisionEventPage;
use App\Exceptions\HikvisionTransportException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class HikvisionIsapiClient
{
    public function __construct(private readonly HikvisionErrorSanitizer $sanitizer) {}

    public function testConnection(): HikvisionConnectionResult
    {
        if (! config('hikvision.enabled')) {
            return new HikvisionConnectionResult(false, 'Unexpected Response', 'Hikvision integration is disabled.');
        }

        try {
            $device = $this->decode($this->get('/ISAPI/System/deviceInfo'));
            $capabilities = $this->decode($this->get('/ISAPI/AccessControl/AcsEvent/capabilities'));

            return new HikvisionConnectionResult(true, 'Connected', 'ISAPI device and event capabilities are available.', [
                'model' => $this->scalar($device, ['DeviceInfo.model', 'model']),
                'serial_number' => $this->scalar($device, ['DeviceInfo.serialNumber', 'serialNumber']),
                'firmware' => $this->scalar($device, ['DeviceInfo.firmwareVersion', 'firmwareVersion']),
                'event_capabilities' => $capabilities !== [],
            ]);
        } catch (HikvisionTransportException $exception) {
            return new HikvisionConnectionResult(false, $this->connectionStatus($exception->safeCode), $exception->safeMessage);
        }
    }

    public function searchEvents(
        CarbonImmutable $from,
        CarbonImmutable $to,
        string $searchId,
        int $position,
        int $limit,
    ): HikvisionEventPage {
        $response = $this->post('/ISAPI/AccessControl/AcsEvent?format=json', [
            'AcsEventCond' => [
                'searchID' => $searchId,
                'searchResultPosition' => $position,
                'maxResults' => $limit,
                // Zero is Hikvision's all-code search. Qualification happens from
                // the employee authentication payload returned by AcsEvent.
                'major' => (int) config('hikvision.event_query_major', 0),
                'minor' => (int) config('hikvision.event_query_minor', 0),
                'startTime' => $from->toIso8601String(),
                'endTime' => $to->toIso8601String(),
            ],
        ]);
        $payload = $this->decode($response);
        $root = data_get($payload, 'AcsEvent', $payload);
        $events = data_get($root, 'InfoList', []);
        if (isset($events['major']) || isset($events['serialNo'])) {
            $events = [$events];
        }
        if (! is_array($events)) {
            throw new HikvisionTransportException('unexpected_response', 'The device returned an invalid event list.');
        }
        $status = strtoupper((string) data_get($root, 'responseStatusStrg', ''));
        if ($status === '') {
            throw new HikvisionTransportException('unexpected_response', 'The device omitted the event search status.');
        }

        return new HikvisionEventPage(array_values($events), $status === 'MORE', $position + count($events));
    }

    private function request(): PendingRequest
    {
        $host = trim((string) config('hikvision.host'));
        $scheme = (string) config('hikvision.scheme', 'http');
        $port = (int) config('hikvision.port', 80);
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new HikvisionTransportException('configuration_invalid', 'Hikvision connection settings are incomplete.');
        }

return Http::baseUrl("{$scheme}://{$host}:{$port}")
    ->withoutVerifying()
    ->withOptions([
        'auth' => [
            (string) config('hikvision.username'),
            (string) config('hikvision.password'),
            'digest',  ],
      ]);
}

    private function get(string $uri): Response
    {
        return $this->send(fn (): Response => $this->request()->get($uri));
    }

    /** @param array<string, mixed> $payload */
    private function post(string $uri, array $payload): Response
    {
        return $this->send(fn (): Response => $this->request()->post($uri, $payload));
    }

  /** @param callable(): Response $request */
private function send(callable $request): Response
{
    try {
        $response = $request();
    } catch (ConnectionException $exception) {
        throw $this->connectionException($exception);
    }

    if ($response->failed()) {
        $status = $response->status();

        Log::warning('Hikvision ISAPI request failed.', [
            'http_status' => $status,
            'content_type' => $response->header('Content-Type'),
            'response_excerpt' => mb_substr($response->body(), 0, 1000),
        ]);

        throw new HikvisionTransportException(
            $status === 401 ? 'authentication_failed' : 'device_http_error',
            $status === 401
                ? 'Hikvision authentication failed.'
                : "The Hikvision device rejected the request with HTTP {$status}.",
        );
    }

    return $response;
}

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        try {
            $json = $response->json();
            if (is_array($json)) {
                return $json;
            }
            $xml = @simplexml_load_string($response->body(), 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
            if ($xml !== false) {
                return json_decode(json_encode($xml, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            }
        } catch (Throwable) {
            // Converted to a controlled response error below.
        }

        throw new HikvisionTransportException('unexpected_response', 'The device returned an unreadable response.');
    }

    private function connectionException(ConnectionException $exception): HikvisionTransportException
    {
        $message = strtolower($exception->getMessage());
        $timeout = str_contains($message, 'timed out') || str_contains($message, 'timeout');

        return new HikvisionTransportException(
            $timeout ? 'timeout' : 'device_unreachable',
            $timeout ? 'The Hikvision request timed out.' : 'The Hikvision device is unreachable.',
        );
    }

    private function connectionStatus(string $code): string
    {
        return match ($code) {
            'authentication_failed' => 'Authentication Failed',
            'device_unreachable' => 'Device Unreachable',
            'timeout' => 'Timeout',
            default => 'Unexpected Response',
        };
    }

    /** @param array<int, string> $paths */
    private function scalar(array $payload, array $paths): string|int|bool|null
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_scalar($value)) {
                return $value;
            }
        }

        return null;
    }
}
