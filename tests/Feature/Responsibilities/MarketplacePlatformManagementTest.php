<?php

namespace Tests\Feature\Responsibilities;

use App\Actions\Responsibilities\ChangeMarketplacePlatformCode;
use App\Actions\Responsibilities\CreateMarketplacePlatform;
use App\Actions\Responsibilities\CreateResponsibilityAssignment;
use App\Actions\Responsibilities\RenameMarketplacePlatform;
use App\Actions\Responsibilities\SetMarketplacePlatformStatus;
use App\DTOs\Responsibilities\ChangeMarketplacePlatformCodeData;
use App\DTOs\Responsibilities\ChangeMarketplacePlatformStatusData;
use App\DTOs\Responsibilities\CreateMarketplacePlatformData;
use App\DTOs\Responsibilities\RenameMarketplacePlatformData;
use App\Enums\EmployeeRole;
use App\Exceptions\HardDeletionProhibitedException;
use App\Exceptions\MarketplacePlatformCodeChangeException;
use App\Filament\Resources\MarketplacePlatforms\Pages\EditMarketplacePlatform;
use App\Models\ActivityLog;
use App\Models\MarketplacePlatform;
use App\Models\ResponsibilityAssignment;
use App\Models\StockMovement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MarketplacePlatformManagementTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_owner_normalizes_renames_and_deactivates_platform_with_safe_audit(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $platform = app(CreateMarketplacePlatform::class)->handle(new CreateMarketplacePlatformData('  Amazon   UAE ', 'Amazon UAE'), $owner);
        $this->assertSame('Amazon UAE', $platform->name);
        $this->assertSame('amazon uae', $platform->normalized_name);
        $this->assertSame('amazon_uae', $platform->code);
        app(RenameMarketplacePlatform::class)->handle($platform, new RenameMarketplacePlatformData('Amazon United Arab Emirates'), $owner);
        app(SetMarketplacePlatformStatus::class)->handle($platform, new ChangeMarketplacePlatformStatusData(false, 'Channel paused'), $owner);

        $this->assertFalse($platform->refresh()->status);
        $this->assertSame(['marketplace_platform.created', 'marketplace_platform.renamed', 'marketplace_platform.status_changed'], ActivityLog::query()->orderBy('id')->pluck('event')->all());
    }

    public function test_logical_duplicate_staff_management_and_hard_delete_are_rejected(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        app(CreateMarketplacePlatform::class)->handle(new CreateMarketplacePlatformData('Amazon UAE', 'amazon_uae'), $owner);

        try {
            app(CreateMarketplacePlatform::class)->handle(new CreateMarketplacePlatformData(' amazon   uae ', 'amazon_second'), $owner);
            $this->fail('Logical duplicate should fail.');
        } catch (ValidationException) {
            $this->assertSame(1, MarketplacePlatform::query()->count());
        }

        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        try {
            app(CreateMarketplacePlatform::class)->handle(new CreateMarketplacePlatformData('Noon UAE', 'noon_uae'), $staff);
            $this->fail('Staff must not manage Platforms.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('marketplace_platforms', ['code' => 'noon_uae']);
        }

        $this->expectException(HardDeletionProhibitedException::class);
        MarketplacePlatform::query()->firstOrFail()->delete();
    }

    public function test_owner_and_admin_can_change_unused_platform_codes_with_safe_audit(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $admin = $this->responsibilityUser(EmployeeRole::Admin);
        $amazon = MarketplacePlatform::factory()->create(['code' => 'amazon']);
        $noon = MarketplacePlatform::factory()->create(['code' => 'noon']);
        $assignmentCount = ResponsibilityAssignment::query()->count();
        $movementCount = StockMovement::query()->count();

        app(ChangeMarketplacePlatformCode::class)->handle($amazon, new ChangeMarketplacePlatformCodeData('amazon_uae', 'Correct regional code'), $owner);
        app(ChangeMarketplacePlatformCode::class)->handle($noon, new ChangeMarketplacePlatformCodeData('noon_uae', 'Correct regional code'), $admin);

        $this->assertSame('amazon_uae', $amazon->refresh()->code);
        $this->assertSame('noon_uae', $noon->refresh()->code);
        $this->assertSame($assignmentCount, ResponsibilityAssignment::query()->count());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertDatabaseCount('activity_logs', 2);
        $log = ActivityLog::query()->where('subject_id', $amazon->id)->firstOrFail();
        $this->assertSame('marketplace_platform.code_changed', $log->event);
        $this->assertSame($owner->id, $log->actor_user_id);
        $this->assertSame([
            'platform_id' => $amazon->id,
            'old_code' => 'amazon',
            'new_code' => 'amazon_uae',
            'actor_user_id' => $owner->id,
            'reason' => 'Correct regional code',
        ], $log->properties);
    }

    public function test_manager_and_staff_cannot_change_platform_code(): void
    {
        $platform = MarketplacePlatform::factory()->create(['code' => 'amazon']);

        foreach ([EmployeeRole::Manager, EmployeeRole::Staff] as $role) {
            $actor = $this->responsibilityUser($role);

            try {
                app(ChangeMarketplacePlatformCode::class)->handle($platform, new ChangeMarketplacePlatformCodeData('amazon_uae', 'Correction'), $actor);
                $this->fail("{$role->value} must not change Platform codes.");
            } catch (AuthorizationException) {
                $this->assertSame('amazon', $platform->refresh()->code);
            }
        }
    }

    public function test_change_code_requires_unique_valid_code_and_reason(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $platform = MarketplacePlatform::factory()->create(['code' => 'amazon']);
        MarketplacePlatform::factory()->create(['code' => 'noon_uae']);

        foreach ([
            new ChangeMarketplacePlatformCodeData('noon_uae', 'Duplicate'),
            new ChangeMarketplacePlatformCodeData('invalid!', 'Invalid characters'),
            new ChangeMarketplacePlatformCodeData('amazon_uae', '   '),
        ] as $data) {
            try {
                app(ChangeMarketplacePlatformCode::class)->handle($platform, $data, $owner);
                $this->fail('Invalid Platform code correction must fail.');
            } catch (ValidationException) {
                $this->assertSame('amazon', $platform->refresh()->code);
            }
        }
    }

    public function test_referenced_platform_code_change_is_rejected_without_operational_changes(): void
    {
        $foundation = $this->responsibilityFoundation();
        app(CreateResponsibilityAssignment::class)->handle(
            $this->assignmentData($foundation, overrides: ['platformId' => $foundation['platform']->id]),
            $foundation['owner'],
        );
        $assignment = ResponsibilityAssignment::query()->firstOrFail();
        $inventoryBefore = $foundation['inventory']->fresh()->getAttributes();
        $movementCount = StockMovement::query()->count();

        try {
            app(ChangeMarketplacePlatformCode::class)->handle(
                $foundation['platform'],
                new ChangeMarketplacePlatformCodeData('amazon_corrected', 'Correct entry'),
                $foundation['owner'],
            );
            $this->fail('Referenced Platform code must remain immutable.');
        } catch (MarketplacePlatformCodeChangeException $exception) {
            $this->assertSame('Platform code cannot be changed because this Platform is already in use.', $exception->getMessage());
        }

        $this->assertSame('amazon_uae', $foundation['platform']->refresh()->code);
        $this->assertSame($assignment->getAttributes(), $assignment->fresh()->getAttributes());
        $this->assertSame($inventoryBefore, $foundation['inventory']->fresh()->getAttributes());
        $this->assertSame($movementCount, StockMovement::query()->count());
        $this->assertDatabaseMissing('activity_logs', ['event' => 'marketplace_platform.code_changed']);
    }

    public function test_name_edit_remains_available_but_generic_updates_cannot_change_code(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $platform = MarketplacePlatform::factory()->create(['name' => 'Amazon', 'normalized_name' => 'amazon', 'code' => 'amazon']);

        app(RenameMarketplacePlatform::class)->handle($platform, new RenameMarketplacePlatformData('Amazon UAE'), $owner);
        $platform->update(['code' => 'tampered']);

        $this->assertSame('Amazon UAE', $platform->refresh()->name);
        $this->assertSame('amazon', $platform->code);

        $this->expectException(MarketplacePlatformCodeChangeException::class);
        $platform->forceFill(['code' => 'tampered'])->save();
    }

    public function test_normal_edit_page_changes_name_but_never_dehydrates_code(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $platform = MarketplacePlatform::factory()->create(['name' => 'Amazon', 'normalized_name' => 'amazon', 'code' => 'amazon']);

        $this->actingAs($owner);
        Livewire::test(EditMarketplacePlatform::class, ['record' => $platform->getRouteKey()])
            ->assertFormSet(['name' => 'Amazon', 'code' => 'amazon'])
            ->fillForm(['name' => 'Amazon UAE', 'code' => 'tampered'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Amazon UAE', $platform->refresh()->name);
        $this->assertSame('amazon', $platform->code);
    }
}
