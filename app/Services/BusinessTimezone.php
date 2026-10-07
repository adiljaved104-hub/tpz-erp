<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

class BusinessTimezone
{
    public function name(): string
    {
        $zone = (string) config('business.timezone', 'Asia/Dubai');

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'Asia/Dubai';
    }

    public function date(DateTimeInterface|string|null $instant): ?CarbonImmutable
    {
        return $instant === null ? null : CarbonImmutable::parse($instant, config('app.timezone', 'UTC'))->setTimezone($this->name());
    }

    public function format(DateTimeInterface|string|null $instant, string $format = 'd M Y H:i T'): ?string
    {
        return $this->date($instant)?->format($format);
    }

    public function iso(DateTimeInterface|string|null $instant): ?string
    {
        return $this->date($instant)?->toIso8601String();
    }
}
