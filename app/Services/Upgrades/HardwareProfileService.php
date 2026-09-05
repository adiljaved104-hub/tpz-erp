<?php

namespace App\Services\Upgrades;

use App\Enums\ComponentType;
use App\Enums\HardwareSubsystem;
use App\Enums\InventoryItemType;
use App\Enums\UpgradePermission;
use App\Models\Component;
use App\Models\Product;
use App\Models\ProductHardwareProfile;
use App\Models\UpgradeRecipe;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\UpgradeAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HardwareProfileService
{
    public function __construct(
        private readonly UpgradeAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $data */
    public function save(Product $product, array $data, User $actor): ProductHardwareProfile
    {
        $this->authorization->authorize($actor, UpgradePermission::ManageHardwareProfiles);
        throw_if($product->inventory_item_type !== InventoryItemType::Product, ValidationException::withMessages(['product_id' => 'Hardware Profiles may only be created for normal Products.']));
        $validated = $this->validate($data);
        $slots = $this->normalizeSlots($validated['slots'] ?? []);

        return DB::transaction(function () use ($product, $validated, $slots, $actor): ProductHardwareProfile {
            $product = Product::query()->lockForUpdate()->findOrFail($product->id);
            $profile = ProductHardwareProfile::query()->lockForUpdate()->where('product_id', $product->id)->first();
            $creating = $profile === null;

            if ($profile === null) {
                $profile = new ProductHardwareProfile([
                    'product_id' => $product->id,
                    'profile_version' => 1,
                    'created_by_user_id' => $actor->id,
                ]);
            }

            $oldStructure = $creating ? null : $this->structuralSnapshot($profile->load('slots'));
            $newStructure = $this->structuralSnapshotFromData($validated, $slots);
            $structuralChange = ! $creating && $oldStructure !== $newStructure;

            $profile->forceFill([
                'ram_upgradeable' => $validated['ram_upgradeable'],
                'max_supported_ram_mb' => $validated['max_supported_ram_mb'] ?? null,
                'storage_upgradeable' => $validated['storage_upgradeable'],
                'notes' => $validated['notes'] ?? null,
                'profile_version' => $structuralChange ? $profile->profile_version + 1 : $profile->profile_version,
                'updated_by_user_id' => $actor->id,
            ])->save();

            if ($creating || $structuralChange) {
                $profile->slots()->delete();
                $profile->slots()->createMany($slots);
            }

            if ($structuralChange) {
                $profile->product->salesConfigurations()->update(['active' => false, 'updated_by_user_id' => $actor->id]);
                UpgradeRecipe::query()->whereHas('salesConfiguration', fn ($query) => $query->where('product_id', $product->id))
                    ->update(['active' => false, 'updated_by_user_id' => $actor->id]);
            }

            $this->activity->log($creating ? 'hardware_profile.created' : 'hardware_profile.updated', $actor, $profile);

            return $profile->load(['product', 'slots.baseComponent.product']);
        });
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validate(array $data): array
    {
        return Validator::make($data, [
            'ram_upgradeable' => ['required', 'boolean'],
            'max_supported_ram_mb' => ['nullable', 'integer', 'min:1'],
            'storage_upgradeable' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'slots' => ['present', 'array'],
            'slots.*.subsystem' => ['required', Rule::enum(HardwareSubsystem::class)],
            'slots.*.slot_key' => ['required', 'string', 'max:64'],
            'slots.*.interface_type' => ['nullable', 'string', 'max:120'],
            'slots.*.is_soldered' => ['required', 'boolean'],
            'slots.*.is_occupied' => ['required', 'boolean'],
            'slots.*.base_component_id' => ['nullable', 'integer', Rule::exists('components', 'id')],
            'slots.*.base_capacity_value' => ['nullable', 'numeric', 'gt:0'],
            'slots.*.base_capacity_unit' => ['nullable', Rule::in(['mb', 'gb', 'tb'])],
            'slots.*.position' => ['nullable', 'integer', 'min:0'],
            'slots.*.notes' => ['nullable', 'string', 'max:1000'],
        ])->after(function ($validator) use ($data): void {
            $keys = [];
            foreach ($data['slots'] ?? [] as $index => $slot) {
                $key = strtoupper(trim((string) ($slot['slot_key'] ?? '')));
                if ($key !== '' && isset($keys[$key])) {
                    $validator->errors()->add("slots.{$index}.slot_key", 'Slot keys must be unique within a Hardware Profile.');
                }
                $keys[$key] = true;
                if (($slot['is_soldered'] ?? false) && ! ($slot['is_occupied'] ?? false)) {
                    $validator->errors()->add("slots.{$index}.is_occupied", 'A soldered slot must be occupied.');
                }
                if (filled($slot['base_capacity_value'] ?? null) xor filled($slot['base_capacity_unit'] ?? null)) {
                    $validator->errors()->add("slots.{$index}.base_capacity_value", 'Base capacity value and unit must be provided together.');
                }
                if (filled($slot['base_component_id'] ?? null) && ! ($slot['is_occupied'] ?? false)) {
                    $validator->errors()->add("slots.{$index}.base_component_id", 'An empty slot cannot have a base component.');
                }
                if (filled($slot['base_component_id'] ?? null)) {
                    $component = Component::query()->find($slot['base_component_id']);
                    $expected = ($slot['subsystem'] ?? null) === HardwareSubsystem::Ram->value ? ComponentType::Ram : ComponentType::Ssd;
                    if ($component?->component_type !== $expected) {
                        $validator->errors()->add("slots.{$index}.base_component_id", 'The base component does not match the selected subsystem.');
                    }
                }
            }
        })->validate();
    }

    /** @param list<array<string, mixed>> $slots @return list<array<string, mixed>> */
    private function normalizeSlots(array $slots): array
    {
        return collect($slots)->values()->map(fn (array $slot, int $index): array => [
            'subsystem' => $slot['subsystem'],
            'slot_key' => strtoupper(trim($slot['slot_key'])),
            'interface_type' => filled($slot['interface_type'] ?? null) ? trim($slot['interface_type']) : null,
            'is_soldered' => (bool) $slot['is_soldered'],
            'is_occupied' => (bool) $slot['is_occupied'],
            'base_component_id' => $slot['base_component_id'] ?? null,
            'base_capacity_value' => $slot['base_capacity_value'] ?? null,
            'base_capacity_unit' => $slot['base_capacity_unit'] ?? null,
            'position' => $slot['position'] ?? $index,
            'notes' => $slot['notes'] ?? null,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function structuralSnapshot(ProductHardwareProfile $profile): array
    {
        return $this->structuralSnapshotFromData($profile->only(['ram_upgradeable', 'max_supported_ram_mb', 'storage_upgradeable']), $profile->slots->map->only(['subsystem', 'slot_key', 'interface_type', 'is_soldered', 'is_occupied', 'base_component_id', 'base_capacity_value', 'base_capacity_unit', 'position'])->all());
    }

    /** @param array<string, mixed> $data @param list<array<string, mixed>> $slots @return array<string, mixed> */
    private function structuralSnapshotFromData(array $data, array $slots): array
    {
        return [
            'ram_upgradeable' => (bool) $data['ram_upgradeable'],
            'max_supported_ram_mb' => isset($data['max_supported_ram_mb']) ? (int) $data['max_supported_ram_mb'] : null,
            'storage_upgradeable' => (bool) $data['storage_upgradeable'],
            'slots' => collect($slots)->map(function (array $slot): array {
                $subsystem = $slot['subsystem'] ?? null;

                return [
                    'subsystem' => $subsystem instanceof HardwareSubsystem ? $subsystem->value : (string) $subsystem,
                    'slot_key' => strtoupper(trim((string) ($slot['slot_key'] ?? ''))),
                    'interface_type' => filled($slot['interface_type'] ?? null) ? strtoupper(trim((string) $slot['interface_type'])) : null,
                    'is_soldered' => (bool) ($slot['is_soldered'] ?? false),
                    'is_occupied' => (bool) ($slot['is_occupied'] ?? false),
                    'base_component_id' => filled($slot['base_component_id'] ?? null) ? (int) $slot['base_component_id'] : null,
                    'base_capacity_value' => filled($slot['base_capacity_value'] ?? null) ? number_format((float) $slot['base_capacity_value'], 4, '.', '') : null,
                    'base_capacity_unit' => filled($slot['base_capacity_unit'] ?? null) ? strtolower((string) $slot['base_capacity_unit']) : null,
                    'position' => (int) ($slot['position'] ?? 0),
                ];
            })->values()->all(),
        ];
    }
}
