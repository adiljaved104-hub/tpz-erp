<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;

class UserTimezoneResolver
{
    public function resolve(?User $user = null, ?Request $request = null): string
    {
        $user ??= auth()->user();
        $request ??= request();

        if ($user instanceof User && $this->valid($user->display_timezone)) {
            return $user->display_timezone;
        }

        if ($user instanceof User && $user->use_device_timezone) {
            $device = $request->header('X-Client-Timezone') ?: $request->input('timezone');
            if ($this->valid($device)) {
                return $device;
            }
        }

        // Attendance work schedules intentionally do not determine display timezone.
        // A verified employee/company regional setting can be added here when such
        // a source exists; otherwise use the configured business timezone.
        return $this->businessTimezone();
    }

    public function date(DateTimeInterface|string|null $value, ?User $user = null, ?Request $request = null): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->setTimezone($this->resolve($user, $request));
    }

    public function format(DateTimeInterface|string|null $value, string $format = 'd M Y H:i T', ?User $user = null, ?Request $request = null): ?string
    {
        return $this->date($value, $user, $request)?->format($format);
    }

    public function iso(DateTimeInterface|string|null $value, ?User $user = null, ?Request $request = null): ?string
    {
        return $this->date($value, $user, $request)?->toIso8601String();
    }

    public function valid(?string $timezone): bool
    {
        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true);
    }

    private function businessTimezone(): string
    {
        $timezone = (string) config('business.timezone', 'Asia/Dubai');

        return $this->valid($timezone) ? $timezone : 'Asia/Dubai';
    }
}
