<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Models\UserDashboardPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DashboardPreferenceService
{
    public function __construct(private readonly DashboardWidgetRegistry $registry) {}

    /** @return array{layout: array<int, string>, hidden: array<int, string>, visible: array<int, string>, has_override: bool} */
    public function resolve(User $user, string $dashboardKey = DashboardWidgetRegistry::DASHBOARD_KEY): array
    {
        $this->validateDashboardKey($dashboardKey);
        $available = $this->registry->defaultLayout($user);
        $preference = UserDashboardPreference::query()
            ->where('user_id', $user->id)
            ->where('dashboard_key', $dashboardKey)
            ->first();

        if (! $preference instanceof UserDashboardPreference) {
            return ['layout' => $available, 'hidden' => [], 'visible' => $available, 'has_override' => false];
        }

        $savedLayout = $this->stringKeys($preference->layout);
        $layout = array_values(array_unique(array_merge(
            array_values(array_intersect($savedLayout, $available)),
            array_values(array_diff($available, $savedLayout)),
        )));
        $hidden = array_values(array_intersect($this->stringKeys($preference->hidden_widgets), $available));

        return [
            'layout' => $layout,
            'hidden' => $hidden,
            'visible' => array_values(array_diff($layout, $hidden)),
            'has_override' => true,
        ];
    }

    /** @param array<int, mixed> $layout @param array<int, mixed> $hidden */
    public function save(User $user, string $dashboardKey, array $layout, array $hidden): UserDashboardPreference
    {
        $this->validateDashboardKey($dashboardKey);
        $layout = $this->validatedKeys($user, $layout, 'layout');
        $hidden = $this->validatedKeys($user, $hidden, 'hidden_widgets');
        $available = $this->registry->defaultLayout($user);

        if (array_diff($hidden, $layout) !== []) {
            throw ValidationException::withMessages(['hidden_widgets' => 'Hidden widgets must also exist in the saved Dashboard layout.']);
        }

        $layout = array_values(array_merge($layout, array_diff($available, $layout)));

        return DB::transaction(function () use ($user, $dashboardKey, $layout, $hidden): UserDashboardPreference {
            User::query()->whereKey($user->id)->lockForUpdate()->value('id');

            $preference = UserDashboardPreference::query()
                ->where('user_id', $user->id)
                ->where('dashboard_key', $dashboardKey)
                ->lockForUpdate()
                ->first() ?? new UserDashboardPreference([
                    'user_id' => $user->id,
                    'dashboard_key' => $dashboardKey,
                ]);

            $preference->layout = $layout;
            $preference->hidden_widgets = $hidden;
            $preference->save();

            return $preference->refresh();
        });
    }

    public function reset(User $user, string $dashboardKey = DashboardWidgetRegistry::DASHBOARD_KEY): void
    {
        $this->validateDashboardKey($dashboardKey);
        UserDashboardPreference::query()
            ->where('user_id', $user->id)
            ->where('dashboard_key', $dashboardKey)
            ->delete();
    }

    /** @param array<int, mixed> $keys @return array<int, string> */
    private function validatedKeys(User $user, array $keys, string $field): array
    {
        $normalized = $this->stringKeys($keys);
        if (count($normalized) !== count($keys)) {
            throw ValidationException::withMessages([$field => 'Dashboard widget keys must be non-empty strings.']);
        }
        if (count($normalized) !== count(array_unique($normalized))) {
            throw ValidationException::withMessages([$field => 'Dashboard widget keys cannot contain duplicates.']);
        }

        $known = $this->registry->keys();
        $unknown = array_diff($normalized, $known);
        if ($unknown !== []) {
            throw ValidationException::withMessages([$field => 'The Dashboard request contains an unknown widget.']);
        }

        $authorized = $this->registry->defaultLayout($user);
        $unauthorized = array_diff($normalized, $authorized);
        if ($unauthorized !== []) {
            throw ValidationException::withMessages([$field => 'The Dashboard request contains a widget you are not authorized to use.']);
        }

        return $normalized;
    }

    /** @param array<int, mixed>|null $keys @return array<int, string> */
    private function stringKeys(?array $keys): array
    {
        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_filter($keys, fn (mixed $key): bool => is_string($key) && $key !== ''));
    }

    private function validateDashboardKey(string $dashboardKey): void
    {
        if ($dashboardKey !== DashboardWidgetRegistry::DASHBOARD_KEY) {
            throw ValidationException::withMessages(['dashboard_key' => 'The Dashboard key is invalid.']);
        }
    }
}
