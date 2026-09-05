<?php

namespace App\Services\Upgrades;

use App\DTOs\Upgrades\UpgradeRecipeValidationResult;
use App\Enums\ComponentType;
use App\Enums\HardwareSubsystem;
use App\Enums\ProductStatus;
use App\Enums\UpgradeRecipeOperation;
use App\Models\Component;
use App\Models\ProductHardwareSlot;
use App\Models\UpgradeRecipe;

class UpgradeRecipeValidationService
{
    public function validate(UpgradeRecipe $recipe): UpgradeRecipeValidationResult
    {
        $recipe->loadMissing(['salesConfiguration.product.hardwareProfile.slots.baseComponent.product', 'lines.installComponent.product', 'lines.recoveredComponent.product']);
        $configuration = $recipe->salesConfiguration;
        $profile = $configuration?->product?->hardwareProfile;
        $errors = [];
        $summary = [];

        if ($profile === null) {
            return new UpgradeRecipeValidationResult(false, ['No Hardware Profile is configured for the base Product.'], [], 'Hardware setup is incomplete.');
        }
        if ($configuration->hardware_profile_version !== $profile->profile_version || $recipe->hardware_profile_version !== $profile->profile_version) {
            $errors[] = 'This recipe is stale because the Product Hardware Profile version has changed.';
        }

        $slots = $profile->slots->mapWithKeys(fn (ProductHardwareSlot $slot): array => [strtoupper($slot->slot_key) => $this->slotState($slot)])->all();

        foreach ($recipe->lines as $line) {
            $operation = $line->operation;
            $sourceKey = strtoupper((string) $line->source_slot_key);
            $targetKey = strtoupper((string) $line->target_slot_key);

            if (bccomp((string) $line->quantity_per_laptop, '1.0000', 4) !== 0) {
                $errors[] = "Line {$line->sequence}: each slot operation must use one component per laptop.";
            }

            if ($operation === UpgradeRecipeOperation::Keep) {
                if (! isset($slots[$sourceKey])) {
                    $errors[] = "Line {$line->sequence}: source slot {$sourceKey} does not exist.";
                } elseif (! $slots[$sourceKey]['occupied']) {
                    $errors[] = "Line {$line->sequence}: source slot {$sourceKey} is empty and cannot be kept.";
                } else {
                    $summary[] = 'Keep '.$this->describeSlot($slots[$sourceKey]);
                }

                continue;
            }

            if ($operation === UpgradeRecipeOperation::Install) {
                $this->install($line->sequence, $targetKey, $line->installComponent, $profile, $slots, $errors, $summary);

                continue;
            }

            if ($operation->isRemoval()) {
                if (! isset($slots[$sourceKey])) {
                    $errors[] = "Line {$line->sequence}: source slot {$sourceKey} does not exist.";

                    continue;
                }
                if (! $slots[$sourceKey]['occupied']) {
                    $errors[] = "Line {$line->sequence}: source slot {$sourceKey} is empty.";

                    continue;
                }
                if ($slots[$sourceKey]['soldered']) {
                    $errors[] = "Line {$line->sequence}: soldered slot {$sourceKey} cannot be removed.";

                    continue;
                }
                if (($slots[$sourceKey]['subsystem'] === HardwareSubsystem::Ram && ! $profile->ram_upgradeable)
                    || ($slots[$sourceKey]['subsystem'] === HardwareSubsystem::Storage && ! $profile->storage_upgradeable)) {
                    $errors[] = "Line {$line->sequence}: {$slots[$sourceKey]['subsystem']->label()} is not upgradeable on this Hardware Profile.";

                    continue;
                }
                if ($line->recovered_component_id !== null && $slots[$sourceKey]['component_id'] !== null && $line->recovered_component_id !== $slots[$sourceKey]['component_id']) {
                    $errors[] = "Line {$line->sequence}: recovered component does not match the component in {$sourceKey}.";
                }
                if (in_array($operation, [UpgradeRecipeOperation::RemoveAndReturn, UpgradeRecipeOperation::RemoveAsDamaged], true)
                    && $line->recovered_component_id === null && $slots[$sourceKey]['component_id'] === null) {
                    $errors[] = "Line {$line->sequence}: the recovered component for {$sourceKey} is unknown.";
                }
                $summary[] = $operation->label().' from '.$sourceKey;
                $slots[$sourceKey]['occupied'] = false;
                $slots[$sourceKey]['component_id'] = null;
                $slots[$sourceKey]['capacity_value'] = null;
                $slots[$sourceKey]['capacity_unit'] = null;
            }
        }

        $layout = $this->resultingLayout($slots, $errors);
        if ($profile->max_supported_ram_mb !== null && $layout['ram_total_mb'] > $profile->max_supported_ram_mb) {
            $errors[] = "Resulting RAM {$layout['ram_total_mb']}MB exceeds the Hardware Profile maximum of {$profile->max_supported_ram_mb}MB.";
        }
        if ($configuration->target_ram_mb !== null && $layout['ram_total_mb'] !== $configuration->target_ram_mb) {
            $errors[] = "Resulting RAM {$layout['ram_total_mb']}MB does not match target {$configuration->target_ram_mb}MB.";
        }
        if ($configuration->target_storage_total_gb !== null && abs($layout['storage_total_gb'] - (float) $configuration->target_storage_total_gb) > 0.0001) {
            $errors[] = "Resulting storage {$layout['storage_total_gb']}GB does not match target {$configuration->target_storage_total_gb}GB.";
        }
        if (is_array($configuration->target_storage_layout) && $this->normalizeTargetLayout($configuration->target_storage_layout) !== $layout['storage_slots']) {
            $errors[] = 'Resulting storage slot layout does not match the Sales Configuration target layout.';
        }

        return new UpgradeRecipeValidationResult($errors === [], array_values(array_unique($errors)), $layout, $summary === [] ? 'No upgrade operations configured.' : implode('; ', $summary).'.');
    }

    /** @return array<string, mixed> */
    private function slotState(ProductHardwareSlot $slot): array
    {
        $component = $slot->baseComponent;

        return [
            'key' => strtoupper($slot->slot_key),
            'subsystem' => $slot->subsystem,
            'interface' => filled($slot->interface_type) ? strtoupper(trim($slot->interface_type)) : null,
            'soldered' => $slot->is_soldered,
            'occupied' => $slot->is_occupied,
            'component_id' => $component?->id,
            'capacity_value' => $slot->base_capacity_value ?? $component?->capacity_value,
            'capacity_unit' => $slot->base_capacity_unit ?? $component?->capacity_unit,
        ];
    }

    /** @param array<string, array<string, mixed>> $slots @param list<string> $errors @param list<string> $summary */
    private function install(int $sequence, string $targetKey, ?Component $component, $profile, array &$slots, array &$errors, array &$summary): void
    {
        if (! isset($slots[$targetKey])) {
            $errors[] = "Line {$sequence}: target slot {$targetKey} does not exist.";

            return;
        }
        if ($slots[$targetKey]['occupied']) {
            $errors[] = "Line {$sequence}: target slot {$targetKey} is already occupied.";

            return;
        }
        if ($slots[$targetKey]['soldered']) {
            $errors[] = "Line {$sequence}: target slot {$targetKey} is soldered and cannot accept an installation.";

            return;
        }
        if ($component === null) {
            $errors[] = "Line {$sequence}: install component is required.";

            return;
        }
        if ($component->product?->status !== ProductStatus::Active) {
            $errors[] = "Line {$sequence}: install component is inactive.";
        }
        $expectedType = $slots[$targetKey]['subsystem'] === HardwareSubsystem::Ram ? ComponentType::Ram : ComponentType::Ssd;
        if ($component->component_type !== $expectedType) {
            $errors[] = "Line {$sequence}: component type is incompatible with {$targetKey}.";
        }
        if ($expectedType === ComponentType::Ram && ! $profile->ram_upgradeable) {
            $errors[] = "Line {$sequence}: RAM is not upgradeable on this Hardware Profile.";
        }
        if ($expectedType === ComponentType::Ssd && ! $profile->storage_upgradeable) {
            $errors[] = "Line {$sequence}: storage is not upgradeable on this Hardware Profile.";
        }
        if (blank($slots[$targetKey]['interface']) || blank($component->interface_type)) {
            $errors[] = "Line {$sequence}: interface facts are incomplete for {$targetKey}.";
        } elseif (strcasecmp(trim($slots[$targetKey]['interface']), trim($component->interface_type)) !== 0) {
            $errors[] = "Line {$sequence}: {$component->interface_type} is incompatible with {$slots[$targetKey]['interface']} slot {$targetKey}.";
        }
        if ($component->capacity_value === null || ! in_array($component->capacity_unit, ['mb', 'gb', 'tb'], true)) {
            $errors[] = "Line {$sequence}: component capacity facts are incomplete.";
        }
        $slots[$targetKey]['occupied'] = true;
        $slots[$targetKey]['component_id'] = $component->id;
        $slots[$targetKey]['capacity_value'] = $component->capacity_value;
        $slots[$targetKey]['capacity_unit'] = $component->capacity_unit;
        $summary[] = 'Install '.$component->specification.' in '.$targetKey;
    }

    /** @param array<string, array<string, mixed>> $slots @param list<string> $errors @return array<string, mixed> */
    private function resultingLayout(array $slots, array &$errors): array
    {
        $ramMb = 0;
        $storageGb = 0.0;
        $storageSlots = [];
        foreach ($slots as $slot) {
            if (! $slot['occupied']) {
                continue;
            }
            if ($slot['capacity_value'] === null || $slot['capacity_unit'] === null) {
                $errors[] = "Occupied slot {$slot['key']} has no usable capacity facts.";

                continue;
            }
            if ($slot['subsystem'] === HardwareSubsystem::Ram) {
                $ramMb += (int) round($this->toMb((float) $slot['capacity_value'], $slot['capacity_unit']));
            } else {
                $capacityGb = $this->toGb((float) $slot['capacity_value'], $slot['capacity_unit']);
                $storageGb += $capacityGb;
                $storageSlots[] = ['slot_key' => $slot['key'], 'capacity_gb' => round($capacityGb, 4), 'interface' => $slot['interface']];
            }
        }
        usort($storageSlots, fn (array $a, array $b): int => $a['slot_key'] <=> $b['slot_key']);

        return ['ram_total_mb' => $ramMb, 'storage_total_gb' => round($storageGb, 4), 'storage_slots' => $storageSlots];
    }

    /** @param list<array<string, mixed>> $layout @return list<array<string, mixed>> */
    private function normalizeTargetLayout(array $layout): array
    {
        $normalized = collect($layout)->map(fn (array $slot): array => [
            'slot_key' => strtoupper(trim((string) ($slot['slot_key'] ?? ''))),
            'capacity_gb' => round((float) ($slot['capacity_gb'] ?? 0), 4),
            'interface' => filled($slot['interface'] ?? null) ? strtoupper(trim($slot['interface'])) : null,
        ])->sortBy('slot_key')->values()->all();

        return $normalized;
    }

    /** @param array<string, mixed> $slot */
    private function describeSlot(array $slot): string
    {
        return trim((string) $slot['capacity_value']).strtoupper((string) $slot['capacity_unit']).' in '.$slot['key'];
    }

    private function toMb(float $value, string $unit): float
    {
        return match ($unit) {
            'mb' => $value,
            'gb' => $value * 1024,
            'tb' => $value * 1024 * 1024,
            default => 0,
        };
    }

    private function toGb(float $value, string $unit): float
    {
        return match ($unit) {
            'mb' => $value / 1024,
            'gb' => $value,
            'tb' => $value * 1024,
            default => 0,
        };
    }
}
