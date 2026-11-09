<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\BusinessTimezone;
use App\Services\UserTimezoneResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class UserTimezoneResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_user_timezone_has_priority_and_converts_utc_instants(): void
    {
        $user = User::factory()->create(['display_timezone' => 'Asia/Karachi']);
        $resolved = app(UserTimezoneResolver::class);

        $this->assertSame('Asia/Karachi', $resolved->resolve($user));
        $this->assertSame('07 Nov 2026 04:00 PKT', $resolved->format('2026-11-06 23:00:00', user: $user));
    }

    public function test_valid_device_timezone_is_used_only_when_enabled(): void
    {
        $user = User::factory()->create(['use_device_timezone' => true]);
        $request = Request::create('/');
        $request->headers->set('X-Client-Timezone', 'Asia/Dubai');
        $resolved = app(UserTimezoneResolver::class);

        $this->assertSame('Asia/Dubai', $resolved->resolve($user, $request));

        $user->forceFill(['use_device_timezone' => false])->save();
        $this->assertSame(config('business.timezone'), $resolved->resolve($user, $request));
    }

    public function test_invalid_preferences_fall_back_to_business_timezone(): void
    {
        $user = User::factory()->create(['display_timezone' => 'Not/AZone', 'use_device_timezone' => true]);
        $request = Request::create('/');
        $request->headers->set('X-Client-Timezone', 'Not/AZone');

        $this->assertSame(config('business.timezone'), app(UserTimezoneResolver::class)->resolve($user, $request));
    }

    public function test_business_timezone_formatter_parses_technical_timestamps_as_utc(): void
    {
        $user = User::factory()->create(['display_timezone' => 'Asia/Karachi']);
        $this->actingAs($user);

        $this->assertSame('07 Nov 2026 04:00 PKT', app(BusinessTimezone::class)->format('2026-11-06 23:00:00'));
    }

    public function test_date_ranges_use_exclusive_utc_end_for_pakistan(): void
    {
        $user = User::factory()->create(['display_timezone' => 'Asia/Karachi']);
        $range = app(UserTimezoneResolver::class)->utcDateRange('2026-11-06', '2026-11-06', $user);

        $this->assertSame('2026-11-05 19:00:00', $range['from']?->toDateTimeString());
        $this->assertSame('2026-11-06 19:00:00', $range['until']?->toDateTimeString());
    }

    public function test_date_ranges_use_exclusive_utc_end_for_uae(): void
    {
        $user = User::factory()->create(['display_timezone' => 'Asia/Dubai']);
        $range = app(UserTimezoneResolver::class)->utcDateRange('2026-11-06', '2026-11-06', $user);

        $this->assertSame('2026-11-05 20:00:00', $range['from']?->toDateTimeString());
        $this->assertSame('2026-11-06 20:00:00', $range['until']?->toDateTimeString());
    }

    public function test_pakistan_report_boundary_is_exclusive(): void
    {
        $user = User::factory()->create(['display_timezone' => 'Asia/Karachi']);
        $range = app(UserTimezoneResolver::class)->utcDateRange('2026-11-06', '2026-11-06', $user);
        $before = CarbonImmutable::parse('2026-11-06 18:59:59', 'UTC');
        $boundary = CarbonImmutable::parse('2026-11-06 19:00:00', 'UTC');

        $this->assertTrue($before->lt($range['until']));
        $this->assertTrue($boundary->equalTo($range['until']));
    }

    public function test_uae_report_boundary_is_exclusive(): void
    {
        $user = User::factory()->create(['display_timezone' => 'Asia/Dubai']);
        $range = app(UserTimezoneResolver::class)->utcDateRange('2026-11-06', '2026-11-06', $user);
        $before = CarbonImmutable::parse('2026-11-06 19:59:59', 'UTC');
        $boundary = CarbonImmutable::parse('2026-11-06 20:00:00', 'UTC');

        $this->assertTrue($before->lt($range['until']));
        $this->assertTrue($boundary->equalTo($range['until']));
    }
}
