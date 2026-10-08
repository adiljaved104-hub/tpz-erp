<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplaceOperationsPermission;
use App\Models\MarketplaceConnection;
use App\Models\User;
use App\Services\Authorization\MarketplaceOperationsAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class NoonConnectionTester
{
    private const LOGIN_URL = 'https://noon-api-gateway.noon.partners/identity/public/v1/api/login';

    public function __construct(
        private readonly MarketplaceOperationsAuthorization $authorization,
        private readonly MarketplaceCredentialReferenceService $references,
    ) {}

    public function test(MarketplaceConnection $connection, User $actor): string
    {
        $this->authorization->authorize($actor, MarketplaceOperationsPermission::Manage);
        if ($connection->driver !== 'noon_api' || $connection->getRawOriginal('credential_reference') !== 'noon_default') {
            throw new AuthorizationException;
        }

        try {
            $credentials = $this->references->credentials($connection);
            if (! ($credentials['enabled'] ?? false) || blank($credentials['key_id'] ?? null) || blank($credentials['project_code'] ?? null) || blank($credentials['private_key'] ?? null)) {
                return 'Not configured';
            }

            $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $body = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)).'.'
                .$encode(json_encode(['sub' => $credentials['key_id'], 'iat' => time(), 'jti' => (string) Str::uuid()], JSON_THROW_ON_ERROR));
            if (! @openssl_sign($body, $signature, (string) $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                return 'Authentication failed';
            }
            $jwt = $body.'.'.$encode($signature);

            $response = Http::acceptJson()->asJson()->withHeaders(['User-Agent' => 'TPZ-ERP-Marketplace-Monitor/1.0'])->timeout(12)
                ->post(self::LOGIN_URL, ['token' => $jwt, 'default_project_code' => $credentials['project_code']]);
            if ($response->successful()) {
                $hasSession = collect($response->toPsrResponse()->getHeader('Set-Cookie'))
                    ->contains(fn (string $cookie): bool => str_contains(explode(';', $cookie, 2)[0], '='));

                return $hasSession ? 'Connected' : 'Authentication failed';
            }

            return match ($response->status()) {
                400, 401, 422 => 'Authentication failed',
                403 => 'Permission denied',
                429 => 'Rate limited',
                default => 'Service unavailable',
            };
        } catch (Throwable) {
            return 'Service unavailable';
        }
    }
}
