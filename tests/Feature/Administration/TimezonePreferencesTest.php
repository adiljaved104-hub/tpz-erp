<?php

namespace Tests\Feature\Administration;

use App\Enums\EmployeeRole;
use App\Filament\Pages\Administration\TimezonePreferences;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TimezonePreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_save_valid_timezone_preferences(): void
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Staff)->create(['email' => $user->email]);

        Livewire::actingAs($user)->test(TimezonePreferences::class)
            ->set('displayTimezone', 'Asia/Karachi')
            ->set('useDeviceTimezone', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'display_timezone' => 'Asia/Karachi', 'use_device_timezone' => 1]);
    }

    public function test_invalid_timezone_is_rejected_without_persisting(): void
    {
        $user = User::factory()->create();
        Employee::factory()->for($user)->role(EmployeeRole::Staff)->create(['email' => $user->email]);

        Livewire::actingAs($user)->test(TimezonePreferences::class)
            ->set('displayTimezone', 'Not/AZone')
            ->call('save')
            ->assertHasErrors(['displayTimezone']);

        $this->assertNull($user->fresh()->display_timezone);
    }
}
