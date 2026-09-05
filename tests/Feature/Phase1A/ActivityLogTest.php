<?php

namespace Tests\Feature\Phase1A;

use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_and_financial_payload_keys_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ActivityLogger::class)->log('unsafe', properties: ['password_hash' => 'forbidden']);
    }

    public function test_activity_logs_cannot_be_updated_or_deleted(): void
    {
        $log = app(ActivityLogger::class)->log('safe', properties: ['role' => 'staff']);

        try {
            $log->update(['event' => 'changed']);
            $this->fail('Update should have failed.');
        } catch (LogicException) {
            $this->assertSame('safe', $log->fresh()->event);
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }
}
