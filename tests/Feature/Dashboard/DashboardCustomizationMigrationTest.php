<?php

namespace Tests\Feature\Dashboard;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class DashboardCustomizationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_preferences_schema_is_minimal_unique_and_restrictive(): void
    {
        $this->assertTrue(Schema::hasTable('user_dashboard_preferences'));
        $this->assertSame([
            'id', 'user_id', 'dashboard_key', 'layout', 'hidden_widgets', 'created_at', 'updated_at',
        ], Schema::getColumnListing('user_dashboard_preferences'));
        $this->assertDatabaseCount('user_dashboard_preferences', 0);

        $foreignKey = collect(DB::select("PRAGMA foreign_key_list('user_dashboard_preferences')"))
            ->firstWhere('from', 'user_id');
        $this->assertNotNull($foreignKey);
        $this->assertSame('users', $foreignKey->table);
        $this->assertSame('id', $foreignKey->to);
        $this->assertSame('RESTRICT', strtoupper($foreignKey->on_delete));

        $hasUniqueUserDashboardIndex = collect(DB::select("PRAGMA index_list('user_dashboard_preferences')"))
            ->contains(function (object $index): bool {
                if ((int) $index->unique !== 1) {
                    return false;
                }

                return collect(DB::select("PRAGMA index_info('{$index->name}')"))
                    ->pluck('name')
                    ->all() === ['user_id', 'dashboard_key'];
            });
        $this->assertTrue($hasUniqueUserDashboardIndex);
    }

    public function test_dashboard_preferences_require_array_json_valid_key_and_known_user(): void
    {
        $user = User::factory()->create();

        foreach ([
            ['dashboard_key' => ''],
            ['layout' => json_encode(['orders' => true], JSON_THROW_ON_ERROR)],
            ['hidden_widgets' => json_encode(['revenue' => true], JSON_THROW_ON_ERROR)],
            ['user_id' => $user->id + 999],
        ] as $invalid) {
            $this->expectQueryFailure(fn () => DB::table('user_dashboard_preferences')->insert(
                array_merge($this->validPreference($user), $invalid),
            ));
        }

        DB::table('user_dashboard_preferences')->insert($this->validPreference($user));

        $this->expectQueryFailure(fn () => DB::table('user_dashboard_preferences')->insert(
            $this->validPreference($user),
        ));
    }

    public function test_rollback_refuses_to_discard_existing_dashboard_preferences(): void
    {
        $user = User::factory()->create();
        DB::table('user_dashboard_preferences')->insert($this->validPreference($user));
        $migration = require database_path('migrations/2026_08_30_090000_create_user_dashboard_preferences_table.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rollback refused: user Dashboard preferences exist.');

        $migration->down();
    }

    /** @return array<string, mixed> */
    private function validPreference(User $user): array
    {
        return [
            'user_id' => $user->id,
            'dashboard_key' => 'erp',
            'layout' => json_encode(['orders', 'tasks'], JSON_THROW_ON_ERROR),
            'hidden_widgets' => json_encode(['chat_unread'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function expectQueryFailure(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a database constraint violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
