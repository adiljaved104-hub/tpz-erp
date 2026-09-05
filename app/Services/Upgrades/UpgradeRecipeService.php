<?php

namespace App\Services\Upgrades;

use App\Enums\InventoryPermission;
use App\Enums\RecoveryValuationMethod;
use App\Enums\UpgradePermission;
use App\Enums\UpgradeRecipeOperation;
use App\Models\SalesConfiguration;
use App\Models\UpgradeRecipe;
use App\Models\UpgradeRecipeLine;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\UpgradeAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpgradeRecipeService
{
    public function __construct(
        private readonly UpgradeAuthorization $authorization,
        private readonly InventoryAuthorization $inventoryAuthorization,
        private readonly UpgradeRecipeValidationService $validator,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(SalesConfiguration $configuration, array $data, User $actor): UpgradeRecipe
    {
        $this->authorization->authorize($actor, UpgradePermission::ManageRecipes);
        $validated = $this->validate($data, $actor, $configuration);

        return DB::transaction(function () use ($configuration, $validated, $actor): UpgradeRecipe {
            $configuration = SalesConfiguration::query()->lockForUpdate()->with('product.hardwareProfile')->findOrFail($configuration->id);
            $this->assertCurrent($configuration);
            if ($validated['preferred']) {
                UpgradeRecipe::query()->where('sales_configuration_id', $configuration->id)->update(['preferred' => false]);
            }
            $recipe = UpgradeRecipe::query()->create([
                'sales_configuration_id' => $configuration->id,
                'hardware_profile_version' => $configuration->hardware_profile_version,
                'name' => trim($validated['name']),
                'preferred' => $validated['preferred'],
                'priority' => $validated['priority'],
                'labour_unit_cost' => $validated['labour_unit_cost'] ?? 0,
                'active' => $validated['active'],
                'created_by_user_id' => $actor->id,
                'updated_by_user_id' => $actor->id,
            ]);
            $this->persistLines($recipe, $validated['lines'], $actor);
            $this->assertValidRecipe($recipe);
            $this->activity->log('upgrade_recipe.created', $actor, $recipe);

            return $recipe->load(['salesConfiguration.product.hardwareProfile', 'lines.installComponent.product', 'lines.recoveredComponent.product']);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(UpgradeRecipe $recipe, array $data, User $actor): UpgradeRecipe
    {
        $this->authorization->authorize($actor, UpgradePermission::ManageRecipes);
        $validated = $this->validate($data, $actor, $recipe->salesConfiguration, $recipe);

        return DB::transaction(function () use ($recipe, $validated, $actor): UpgradeRecipe {
            $recipe = UpgradeRecipe::query()->lockForUpdate()->with('salesConfiguration.product.hardwareProfile')->findOrFail($recipe->id);
            $this->assertCurrent($recipe->salesConfiguration);
            if ($validated['preferred']) {
                UpgradeRecipe::query()->where('sales_configuration_id', $recipe->sales_configuration_id)->whereKeyNot($recipe->id)->update(['preferred' => false]);
            }
            $recipe->forceFill([
                'name' => trim($validated['name']), 'preferred' => $validated['preferred'], 'priority' => $validated['priority'],
                'labour_unit_cost' => $validated['labour_unit_cost'] ?? 0, 'active' => $validated['active'], 'updated_by_user_id' => $actor->id,
            ])->save();
            $recipe->lines()->delete();
            $this->persistLines($recipe, $validated['lines'], $actor);
            $this->assertValidRecipe($recipe);
            $this->activity->log('upgrade_recipe.updated', $actor, $recipe);

            return $recipe->refresh()->load(['salesConfiguration.product.hardwareProfile', 'lines.installComponent.product', 'lines.recoveredComponent.product']);
        });
    }

    public function markPreferred(UpgradeRecipe $recipe, User $actor): UpgradeRecipe
    {
        $this->authorization->authorize($actor, UpgradePermission::ManageRecipes);

        return DB::transaction(function () use ($recipe, $actor): UpgradeRecipe {
            $recipe = UpgradeRecipe::query()->lockForUpdate()->findOrFail($recipe->id);
            $this->assertValidRecipe($recipe);
            UpgradeRecipe::query()->where('sales_configuration_id', $recipe->sales_configuration_id)->update(['preferred' => false]);
            $recipe->forceFill(['preferred' => true, 'updated_by_user_id' => $actor->id])->save();
            $this->activity->log('upgrade_recipe.preferred', $actor, $recipe);

            return $recipe;
        });
    }

    public function revalidateConfiguration(SalesConfiguration $configuration, User $actor): SalesConfiguration
    {
        $this->authorization->authorize($actor, UpgradePermission::ManageConfigurations);
        $this->authorization->authorize($actor, UpgradePermission::ManageRecipes);

        return DB::transaction(function () use ($configuration, $actor): SalesConfiguration {
            $configuration = SalesConfiguration::query()->lockForUpdate()->with(['product.hardwareProfile', 'recipes.lines'])->findOrFail($configuration->id);
            $profile = $configuration->product->hardwareProfile;
            throw_if($profile === null, ValidationException::withMessages(['configuration' => 'No Hardware Profile is configured.']));
            $configuration->forceFill(['hardware_profile_version' => $profile->profile_version, 'updated_by_user_id' => $actor->id])->save();
            foreach ($configuration->recipes as $recipe) {
                $recipe->forceFill(['hardware_profile_version' => $profile->profile_version, 'updated_by_user_id' => $actor->id])->save();
                $this->assertValidRecipe($recipe);
            }
            $configuration->forceFill(['active' => true])->save();
            $this->activity->log('sales_configuration.revalidated', $actor, $configuration);

            return $configuration->refresh();
        });
    }

    public function resolvedRecoveryValue(UpgradeRecipeLine $line): string
    {
        return match ($line->recovery_valuation_method) {
            RecoveryValuationMethod::Override => (string) ($line->recovery_value_override ?? '0.0000'),
            RecoveryValuationMethod::CentralApproved => (string) ($line->recoveredComponent?->approved_oem_recovery_value ?? '0.0000'),
            default => '0.0000',
        };
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validate(array $data, User $actor, SalesConfiguration $configuration, ?UpgradeRecipe $recipe = null): array
    {
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255', Rule::unique('upgrade_recipes', 'name')->where('sales_configuration_id', $configuration->id)->ignore($recipe?->id)],
            'preferred' => ['required', 'boolean'],
            'priority' => ['required', 'integer', 'min:0'],
            'labour_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'active' => ['required', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sequence' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.operation' => ['required', Rule::enum(UpgradeRecipeOperation::class)],
            'lines.*.source_slot_key' => ['nullable', 'string', 'max:64'],
            'lines.*.target_slot_key' => ['nullable', 'string', 'max:64'],
            'lines.*.install_component_id' => ['nullable', 'integer', Rule::exists('components', 'id')],
            'lines.*.recovered_component_id' => ['nullable', 'integer', Rule::exists('components', 'id')],
            'lines.*.quantity_per_laptop' => ['required', 'numeric', 'gt:0'],
            'lines.*.recovery_valuation_method' => ['required', Rule::enum(RecoveryValuationMethod::class)],
            'lines.*.recovery_value_override' => ['nullable', 'numeric', 'min:0'],
            'lines.*.override_reason' => ['nullable', 'string', 'max:1000'],
        ])->after(function ($validator) use ($data, $actor): void {
            foreach ($data['lines'] ?? [] as $index => $line) {
                $operation = UpgradeRecipeOperation::tryFrom($line['operation'] instanceof UpgradeRecipeOperation ? $line['operation']->value : (string) ($line['operation'] ?? ''));
                $method = RecoveryValuationMethod::tryFrom($line['recovery_valuation_method'] instanceof RecoveryValuationMethod ? $line['recovery_valuation_method']->value : (string) ($line['recovery_valuation_method'] ?? ''));
                if ($operation === UpgradeRecipeOperation::Keep && blank($line['source_slot_key'] ?? null)) {
                    $validator->errors()->add("lines.{$index}.source_slot_key", 'KEEP requires a source slot.');
                }
                if ($operation === UpgradeRecipeOperation::Install && (blank($line['target_slot_key'] ?? null) || blank($line['install_component_id'] ?? null))) {
                    $validator->errors()->add("lines.{$index}.target_slot_key", 'INSTALL requires a target slot and component.');
                }
                if ($operation?->isRemoval() && blank($line['source_slot_key'] ?? null)) {
                    $validator->errors()->add("lines.{$index}.source_slot_key", 'Removal requires a source slot.');
                }
                if ($method === RecoveryValuationMethod::Override) {
                    if (blank($line['recovery_value_override'] ?? null) && (string) ($line['recovery_value_override'] ?? '') !== '0') {
                        $validator->errors()->add("lines.{$index}.recovery_value_override", 'An override value is required.');
                    }
                    if (mb_strlen(trim((string) ($line['override_reason'] ?? ''))) < 5) {
                        $validator->errors()->add("lines.{$index}.override_reason", 'An override reason of at least 5 characters is required.');
                    }
                    if (! $this->authorization->allows($actor, UpgradePermission::ApproveRecoveryOverride)) {
                        $validator->errors()->add("lines.{$index}.recovery_value_override", 'You are not authorized to approve recovery-value overrides.');
                    }
                }
                if ($operation !== UpgradeRecipeOperation::RemoveAndReturn && $method !== RecoveryValuationMethod::NotApplicable) {
                    $validator->errors()->add("lines.{$index}.recovery_valuation_method", 'Recovery valuation applies only to components returned to inventory.');
                }
                if ($operation === UpgradeRecipeOperation::RemoveAndReturn && $method === RecoveryValuationMethod::CentralApproved && blank($line['recovered_component_id'] ?? null)) {
                    $validator->errors()->add("lines.{$index}.recovered_component_id", 'Central recovery inheritance requires the recovered Component.');
                }
            }
        });

        $validator->after(function ($validator) use ($data, $actor, $recipe): void {
            if (! $this->inventoryAuthorization->allows($actor, InventoryPermission::ViewFinancials)) {
                $incoming = (string) ($data['labour_unit_cost'] ?? '0');
                $existing = (string) ($recipe?->labour_unit_cost ?? '0');
                if (bccomp($incoming, $existing, 4) !== 0) {
                    $validator->errors()->add('labour_unit_cost', 'You are not authorized to change internal labour cost.');
                }
            }
        });

        return $validator->validate();
    }

    /** @param list<array<string, mixed>> $lines */
    private function persistLines(UpgradeRecipe $recipe, array $lines, User $actor): void
    {
        foreach ($lines as $line) {
            $operation = $line['operation'] instanceof UpgradeRecipeOperation ? $line['operation']->value : $line['operation'];
            $method = $line['recovery_valuation_method'] instanceof RecoveryValuationMethod ? $line['recovery_valuation_method']->value : $line['recovery_valuation_method'];
            $override = $method === RecoveryValuationMethod::Override->value;
            $recipe->lines()->create([
                'sequence' => $line['sequence'], 'operation' => $operation,
                'source_slot_key' => filled($line['source_slot_key'] ?? null) ? strtoupper(trim($line['source_slot_key'])) : null,
                'target_slot_key' => filled($line['target_slot_key'] ?? null) ? strtoupper(trim($line['target_slot_key'])) : null,
                'install_component_id' => $line['install_component_id'] ?? null,
                'recovered_component_id' => $line['recovered_component_id'] ?? null,
                'quantity_per_laptop' => $line['quantity_per_laptop'], 'recovery_valuation_method' => $method,
                'recovery_value_override' => $override ? $line['recovery_value_override'] : null,
                'override_reason' => $override ? trim($line['override_reason']) : null,
                'recovery_approved_by_user_id' => $override ? $actor->id : null,
                'recovery_approved_at' => $override ? now() : null,
            ]);
        }
    }

    private function assertCurrent(SalesConfiguration $configuration): void
    {
        throw_if($configuration->isStale(), ValidationException::withMessages(['configuration' => 'The Hardware Profile changed. Revalidate or rebuild this configuration.']));
    }

    private function assertValidRecipe(UpgradeRecipe $recipe): void
    {
        $result = $this->validator->validate($recipe->refresh());
        throw_unless($result->valid, ValidationException::withMessages(['lines' => $result->errors]));
    }
}
