<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\UserTimezoneResolver;
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
}
