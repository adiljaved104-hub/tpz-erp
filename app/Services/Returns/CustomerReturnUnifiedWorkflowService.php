<?php

namespace App\Services\Returns;

use App\DTOs\Returns\CreateCustomerReturnData;
use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\CustomerReturnPermission;
use App\Enums\InventoryLocationType;
use App\Exceptions\CustomerReturnException;
use App\Models\CustomerReturn;
use App\Models\Order;
use App\Models\User;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Orders\OrderReferenceSearchService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CustomerReturnUnifiedWorkflowService
{
    public function __construct(
        private readonly CustomerReturnService $returns,
        private readonly CustomerReturnQuarantineService $quarantine,
        private readonly CustomerReturnAuthorization $authorization,
    ) {}

    /** @param array<string, mixed> $input */
    public function submit(array $input, User $actor, bool $complete, string $submissionKey): CustomerReturn
    {
        $data = Validator::make($input, [
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'receiving_warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'physical_received' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.order_fulfillment_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.return_reason' => ['required', 'string'],
            'items.*.reason_notes' => ['nullable', 'string', 'max:2000'],
            'items.*.qc_outcome' => ['nullable', 'in:pass,fail,mixed,pending,quarantine'],
            'items.*.sellable_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.damaged_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.problem_type' => ['nullable', 'in:damaged,defective,missing_accessories,other,wrong_item,serial_mismatch,empty_package'],
            'items.*.actual_custody_quantity' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'items.*.actual_product_name' => ['nullable', 'string', 'max:255'],
            'items.*.actual_serial_number' => ['nullable', 'string', 'max:255'],
            'items.*.qc_remarks' => ['nullable', 'string', 'max:5000'],
        ])->validate();
        $data['physical_received'] = (bool) ($data['physical_received'] ?? false);
        if (! Str::isUuid($submissionKey)) {
            throw new CustomerReturnException('Invalid submission. Refresh the form and try again.');
        }
        $this->authorization->authorize($actor, CustomerReturnPermission::Create);
        if ($complete && ! $data['physical_received']) {
            throw new CustomerReturnException('Confirm physical receipt before completing QC.');
        }
        if ($data['physical_received']) {
            $this->authorization->authorize($actor, CustomerReturnPermission::Receive);
        }
        $hasQcDecision = collect($data['items'])->contains(fn (array $line): bool => ($line['qc_outcome'] ?? 'pending') !== 'pending');
        if (! $data['physical_received'] && $hasQcDecision) {
            throw new CustomerReturnException('Save the package as pending; QC decisions can be recorded after physical receipt.');
        }
        if ($hasQcDecision) {
            $this->authorization->authorize($actor, CustomerReturnPermission::Inspect);
        }
        foreach ($data['items'] as $line) {
            if ($complete && ($line['qc_outcome'] ?? 'pending') === 'pending') {
                throw new CustomerReturnException('Use Save Pending while any item still needs QC.');
            }
            if (($line['qc_outcome'] ?? 'pending') !== 'pending') {
                if (in_array($line['qc_outcome'], ['fail', 'mixed'], true) && ! in_array($line['problem_type'] ?? null, ['damaged', 'defective', 'missing_accessories', 'other'], true)) {
                    throw new CustomerReturnException('Choose the actual problem for each failed item.');
                }
                if ($line['qc_outcome'] === 'mixed' && ((int) ($line['sellable_quantity'] ?? 0) < 1
                    || (int) ($line['damaged_quantity'] ?? 0) < 1
                    || (int) $line['sellable_quantity'] + (int) $line['damaged_quantity'] !== (int) $line['quantity'])) {
                    throw new CustomerReturnException('Mixed QC quantities must include PASS and FAIL units and add up to the returned quantity.');
                }
                if ($line['qc_outcome'] === 'quarantine' && ! in_array($line['problem_type'] ?? null, ['wrong_item', 'serial_mismatch', 'empty_package'], true)) {
                    throw new CustomerReturnException('Choose the identity or empty-package problem for quarantine.');
                }
            }
        }
        $payloadHash = hash('sha256', json_encode([$data, $complete], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $actor, $complete, $submissionKey, $payloadHash): CustomerReturn {
            $order = Order::query()->with('warehouse')->lockForUpdate()->findOrFail($data['order_id']);
            if (app(OrderReferenceSearchService::class)->findAuthorized($actor, $order->id) === null) {
                throw new AuthorizationException;
            }
            // Marketplace fulfilment has separate disposition/removal/physical
            // receipt rules. The shortcut must not create company inventory there.
            if ($order->warehouse->location_type === InventoryLocationType::MarketplaceFulfilment && ($complete || $data['physical_received'])) {
                throw new CustomerReturnException('Use the Marketplace Return disposition and removal workflow for this Order.');
            }
            if ($existing = CustomerReturn::query()->where('idempotency_key', $submissionKey)->first()) {
                if ($existing->created_by_user_id !== $actor->id || $existing->workflow_payload_hash !== $payloadHash) {
                    throw new CustomerReturnException('This submission ID was already used for a different Return.');
                }

                return $existing;
            }
            $items = array_map(fn (array $line): array => [
                'order_fulfillment_item_id' => (int) $line['order_fulfillment_item_id'],
                'quantity' => (int) $line['quantity'],
                'return_reason' => $line['return_reason'],
                'reason_notes' => $line['reason_notes'] ?? null,
            ], $data['items']);
            $return = $this->returns->create(new CreateCustomerReturnData(
                (int) $order->id, isset($data['receiving_warehouse_id']) ? (int) $data['receiving_warehouse_id'] : null, $items,
                $submissionKey, $data['notes'] ?? null,
            ), $actor);
            $return->forceFill(['workflow_payload_hash' => $payloadHash])->save();
            if (! $data['physical_received']) {
                return $return;
            }
            foreach ($data['items'] as $line) {
                if (($line['qc_outcome'] ?? 'pending') !== 'quarantine') {
                    continue;
                }
                $item = $return->items->firstWhere('order_fulfillment_item_id', (int) $line['order_fulfillment_item_id']);
                $this->quarantine->flagBeforeReceipt($item, [
                    'problem_type' => $line['problem_type'],
                    'actual_custody_quantity' => (int) ($line['actual_custody_quantity'] ?? ($line['problem_type'] === 'empty_package' ? 0 : 1)),
                    'actual_product_name' => $line['actual_product_name'] ?? null,
                    'actual_serial_number' => $line['actual_serial_number'] ?? null,
                    'remarks' => $line['qc_remarks'] ?? null,
                ], $actor);
            }
            $this->returns->receive($return, $actor);
            foreach ($data['items'] as $line) {
                if (in_array($line['qc_outcome'] ?? 'pending', ['quarantine', 'pending'], true)) {
                    continue;
                }
                $item = $return->items->firstWhere('order_fulfillment_item_id', (int) $line['order_fulfillment_item_id']);
                $this->returns->inspect($item, new InspectCustomerReturnItemData(
                    $line['qc_outcome'] === 'pass' ? (int) $line['quantity'] : ($line['qc_outcome'] === 'mixed' ? (int) $line['sellable_quantity'] : 0),
                    $line['qc_outcome'] === 'fail' ? (int) $line['quantity'] : ($line['qc_outcome'] === 'mixed' ? (int) $line['damaged_quantity'] : 0),
                    (string) Str::uuid(),
                    collect([$line['problem_type'] ?? null, $line['qc_remarks'] ?? null])->filter()->implode(' — ') ?: null,
                ), $actor);
            }

            return $return->refresh();
        }, 5);
    }
}
