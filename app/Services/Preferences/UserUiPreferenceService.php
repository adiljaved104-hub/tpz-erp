<?php

namespace App\Services\Preferences;

use App\Models\User;
use App\Models\UserUiPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserUiPreferenceService
{
    public const MY_INVENTORY_COLUMNS = 'my_inventory.columns';

    public const NAVIGATION_HIDDEN_ITEMS = 'navigation.hidden_items';

    public const NAVIGATION_GROUP_ORDER = 'navigation.group_order';

    private const ALLOWED_KEYS = [
        self::MY_INVENTORY_COLUMNS,
        self::NAVIGATION_HIDDEN_ITEMS,
        self::NAVIGATION_GROUP_ORDER,
    ];

    /** @return array<int, string> */
    public function get(User $user, string $key, array $default = []): array
    {
        $this->assertKey($key);
        $value = UserUiPreference::query()
            ->where('user_id', $user->id)
            ->where('preference_key', $key)
            ->first()?->preference_value;

        return is_array($value) ? $this->stringValues($value) : $default;
    }

    /** @param array<int, string> $value */
    public function put(User $user, string $key, array $value): UserUiPreference
    {
        $this->assertKey($key);
        $value = $this->validatedValues($value);

        return DB::transaction(function () use ($user, $key, $value): UserUiPreference {
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');

            $preference = UserUiPreference::query()
                ->where('user_id', $user->id)
                ->where('preference_key', $key)
                ->lockForUpdate()
                ->first() ?? new UserUiPreference([
                    'user_id' => $user->id,
                    'preference_key' => $key,
                ]);

            $preference->preference_value = $value;
            $preference->save();

            return $preference->refresh();
        });
    }

    public function forget(User $user, string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->assertKey($key);
        }

        UserUiPreference::query()
            ->where('user_id', $user->id)
            ->whereIn('preference_key', $keys)
            ->delete();
    }

    private function assertKey(string $key): void
    {
        if (! in_array($key, self::ALLOWED_KEYS, true)) {
            throw ValidationException::withMessages(['preference_key' => 'The UI preference key is invalid.']);
        }
    }

    /** @param array<int, mixed> $value @return array<int, string> */
    private function validatedValues(array $value): array
    {
        $normalized = $this->stringValues($value);
        if (count($normalized) !== count($value) || count($normalized) !== count(array_unique($normalized))) {
            throw ValidationException::withMessages(['preference_value' => 'UI preference values must be unique non-empty strings.']);
        }

        return $normalized;
    }

    /** @param array<int, mixed> $value @return array<int, string> */
    private function stringValues(array $value): array
    {
        return array_values(array_filter(
            $value,
            fn (mixed $item): bool => is_string($item) && $item !== '' && mb_strlen($item) <= 255,
        ));
    }
}
