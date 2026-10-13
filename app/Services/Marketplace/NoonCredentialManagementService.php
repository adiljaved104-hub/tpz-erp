<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplaceOperationsPermission;
use App\Models\MarketplaceConnection;
use App\Models\MarketplaceCredentialVault;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\MarketplaceOperationsAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NoonCredentialManagementService
{
    public function __construct(
        private readonly MarketplaceOperationsAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $input */
    public function save(MarketplaceConnection $connection, array $input, User $actor): void
    {
        $this->authorization->authorize($actor, MarketplaceOperationsPermission::Manage);
        if ($connection->driver !== 'noon_api' || $connection->getRawOriginal('credential_reference') !== 'noon_default') {
            throw new AuthorizationException;
        }

        $validator = validator($input, [
            'key_id' => ['nullable', 'string', 'max:255'],
            'project_code' => ['nullable', 'string', 'max:255'],
            'private_key' => ['nullable', 'string', 'max:20000'],
            'business_model' => ['nullable', 'string', 'max:80'],
        ]);
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->keys() as $field) {
                $errors[$field] = 'Invalid credential field.';
            }

            throw ValidationException::withMessages($errors);
        }
        $validated = $validator->validated();

        DB::transaction(function () use ($connection, $validated, $actor): void {
            $vault = MarketplaceCredentialVault::query()->where('reference', 'noon_default')->lockForUpdate()->first();
            $existing = $vault?->encrypted_payload;
            $existing = is_array($existing) ? $existing : [];
            $keyId = trim((string) ($validated['key_id'] ?? '')) ?: ($existing['key_id'] ?? null);
            $projectCode = trim((string) ($validated['project_code'] ?? '')) ?: ($existing['project_code'] ?? null);
            $privateKey = (string) ($validated['private_key'] ?? '');
            $privateKey = trim($privateKey) === '' ? ($existing['private_key'] ?? null) : $privateKey;

            $missing = [];
            foreach (['key_id' => $keyId, 'project_code' => $projectCode, 'private_key' => $privateKey] as $field => $value) {
                if (blank($value)) {
                    $missing[$field] = 'This field is required when no saved value exists.';
                }
            }
            if ($missing !== []) {
                throw ValidationException::withMessages($missing);
            }

            $vault ??= new MarketplaceCredentialVault;
            $vault->fill([
                'reference' => 'noon_default',
                'encrypted_payload' => [
                    'enabled' => true,
                    'key_id' => $keyId,
                    'project_code' => $projectCode,
                    'private_key' => $privateKey,
                    'business_model' => trim((string) ($validated['business_model'] ?? '')) ?: null,
                ],
            ])->save();
            $this->activity->log('marketplace.noon_credentials_saved', $actor, $connection);
        });
    }
}
