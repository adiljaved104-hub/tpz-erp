<?php

namespace Tests\Feature\Production;

use App\Enums\AuthenticationOtpPurpose;
use App\Notifications\AuthenticationOtpNotification;
use App\Notifications\CriticalAlertMailNotification;
use App\Notifications\LoginEmailChangedNotification;
use App\Services\Operations\BackgroundServiceHealth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BackgroundServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_registers_bounded_non_overlapping_operational_work(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach ([
            'erp:scheduler-heartbeat' => 5,
            'tasks:send-due-notifications' => 10,
            'warranty:send-sla-notifications' => 30,
            'hikvision:sync-attendance' => 10,
            'erp:backup' => 180,
        ] as $command => $maximumLockMinutes) {
            $event = $events->first(fn ($event): bool => str_contains((string) $event->command, $command));

            $this->assertNotNull($event, "Missing scheduled command: {$command}");
            $this->assertTrue($event->withoutOverlapping);
            $this->assertLessThanOrEqual($maximumLockMinutes, $event->expiresAt);
        }
    }

    public function test_scheduler_and_evaluators_record_health_without_business_records(): void
    {
        config([
            'hikvision.enabled' => false,
            'hikvision.scheduled_sync_enabled' => false,
        ]);
        Cache::clear();
        $health = app(BackgroundServiceHealth::class);

        $this->artisan('erp:scheduler-heartbeat')->assertSuccessful();
        $this->artisan('tasks:send-due-notifications')->assertSuccessful();
        $this->artisan('warranty:send-sla-notifications')->assertSuccessful();
        $this->artisan('hikvision:sync-attendance')->assertSuccessful();

        $this->assertNotNull($health->last(BackgroundServiceHealth::SCHEDULER_HEARTBEAT));
        $this->assertNotNull($health->last(BackgroundServiceHealth::TASK_EVALUATOR_SUCCESS));
        $this->assertNotNull($health->last(BackgroundServiceHealth::WARRANTY_EVALUATOR_SUCCESS));
        $this->assertNull($health->last(BackgroundServiceHealth::HIKVISION_ATTEMPT));
        $this->assertDatabaseCount('biometric_attendance_sync_runs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_queue_policy_is_bounded_after_commit_and_otp_remains_synchronous(): void
    {
        $critical = new CriticalAlertMailNotification('Subject', 'Title', 'REF', 'Reason');
        $loginChanged = new LoginEmailChangedNotification(false);
        $otp = new AuthenticationOtpNotification('123456', AuthenticationOtpPurpose::Login);

        $this->assertInstanceOf(ShouldQueue::class, $critical);
        $this->assertSame('notifications', $critical->queue);
        $this->assertTrue($critical->afterCommit);
        $this->assertSame(3, $critical->tries);
        $this->assertSame(60, $critical->timeout);
        $this->assertSame([30, 120, 300], $critical->backoff());

        $this->assertInstanceOf(ShouldQueue::class, $loginChanged);
        $this->assertSame('notifications', $loginChanged->queue);
        $this->assertTrue($loginChanged->afterCommit);
        $this->assertNotInstanceOf(ShouldQueue::class, $otp);
        $this->assertTrue((bool) config('queue.connections.database.after_commit'));
    }

    public function test_database_queue_schema_and_read_only_health_output_are_available(): void
    {
        config([
            'queue.default' => 'database',
            'hikvision.enabled' => false,
            'hikvision.scheduled_sync_enabled' => false,
        ]);
        $this->assertTrue(Schema::hasColumns('jobs', ['queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']));
        $this->assertTrue(Schema::hasColumns('failed_jobs', ['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']));

        $this->artisan('erp:production-check')
            ->expectsOutputToContain('Queue backlog')
            ->expectsOutputToContain('Failed queue jobs')
            ->expectsOutputToContain('Scheduler heartbeat')
            ->expectsOutputToContain('Hikvision scheduler')
            ->assertSuccessful();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }
}
