<?php

namespace App\Services\Qc;

use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\ProductCondition;
use App\Enums\QcPermission;
use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\QcDevice;
use App\Models\QcInspection;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Orders\OrderService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RenewedQcDispatchService
{
    public function requiresQc(OrderItem $item): bool
    {
        return app(RenewedQcRequirement::class)->requires($item);
    }

    public function authorizeQueue(User $actor): void
    {
        app(QcAuthorization::class)->authorize($actor, QcPermission::ViewDispatchQueue);
        app(OrderAuthorization::class)->authorize($actor, OrderPermission::View);
    }

    public function queueQuery(User $actor): Builder
    {
        $this->authorizeQueue($actor);
        $query = Order::query()->where('status', OrderStatus::Reserved->value)
            ->whereHas('items.product', fn ($q) => $q->where('condition', ProductCondition::Renewed->value))
            ->with(['warehouse', 'platform'])->orderBy('order_date')->orderBy('id');
        app(OrderResponsibilityScopeService::class)->applyOrders($query, $actor);

        return $query;
    }

    private function items(Order $order): Collection
    {
        return $order->items()->with(['product', 'upgradeSelection', 'qcAssignments.device', 'qcAssignments.certificate.inspection'])->get();
    }

    public function readiness(Order $order, ?Collection $items = null, bool $locked = false): array
    {
        $items ??= $this->items($order);
        $lines = [];
        $seen = [];
        foreach ($items as $item) {
            $required = app(RenewedQcRequirement::class)->requiredQuantity($item);
            $assignments = $required ? $item->qcAssignments->whereNull('released_at')->whereNotNull('active_device_id') : collect();
            $valid = 0;
            $errors = [];
            foreach ($assignments as $assignment) {
                try {
                    if ($assignment->order_id !== $order->id || $assignment->order_item_id !== $item->id
                        || $assignment->qc_device_id !== $assignment->active_device_id
                        || $assignment->certificate->device_id !== $assignment->qc_device_id
                        || $assignment->certificate->version !== $assignment->certificate_version
                        || isset($seen[$assignment->qc_device_id])) {
                        throw ValidationException::withMessages(['qc' => 'The recorded device/certificate linkage is invalid.']);
                    }
                    $seen[$assignment->qc_device_id] = true;
                    app(QcOrderAssignmentService::class)->validateCertificate($item, $order, $assignment->certificate, locked: $locked);
                    $valid++;
                } catch (ValidationException $e) {
                    $errors[] = $assignment->device->reference.' is not fulfilment-ready. '.collect($e->errors())->flatten()->first();
                }
            }
            $count = $assignments->count();
            if ($assignments->pluck('qc_device_id')->unique()->count() !== $count || $count > $required) {
                $errors[] = 'Assigned devices must be unique and cannot exceed the ordered quantity.';
            }
            $status = ! $required ? 'not_required' : ($errors ? 'attention_required' : ($valid === $required ? 'ready' : ($count ? 'partially_assigned' : 'pending_qc')));
            $lines[] = ['order_item_id' => $item->id, 'product_id' => $item->product_id, 'sku' => $item->sku, 'product' => $item->product_name,
                'condition' => $item->product?->condition?->value, 'quantity' => $item->ordered_quantity, 'required' => $required, 'assigned' => $count,
                'valid' => $valid, 'remaining' => max(0, $required - $valid), 'status' => $status, 'errors' => $errors];
        }
        $required = array_sum(array_column($lines, 'required'));
        $valid = array_sum(array_column($lines, 'valid'));
        $status = ! $required ? 'not_required' : (collect($lines)->contains('status', 'attention_required') ? 'attention_required' : ($valid === $required ? 'ready' : ($valid ? 'partially_assigned' : 'pending_qc')));

        return ['status' => $status, 'required' => $required, 'assigned' => array_sum(array_column($lines, 'assigned')), 'valid' => $valid, 'remaining' => max(0, $required - $valid), 'lines' => $lines];
    }

    public function assertCanShip(Order $order, User $actor): void
    {
        // Called inside OrderService's transaction with Order locked. Hold device
        // locks through stock posting so Re-QC cannot slip between validation/shipment.
        $items = $order->items()->with('upgradeSelection')->orderBy('id')->lockForUpdate()->get();
        $products = Product::query()->whereKey($items->pluck('product_id')->unique()->sort())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($items as $item) {
            $item->setRelation('product', $products[$item->product_id]);
        }
        if (! $items->contains(fn ($item) => $this->requiresQc($item))) {
            return;
        }
        app(QcAuthorization::class)->authorize($actor, QcPermission::ShipDispatch);
        $items->load(['qcAssignments.device', 'qcAssignments.certificate.inspection']);
        $deviceIds = $items->filter(fn ($i) => $this->requiresQc($i))->flatMap->qcAssignments->whereNull('released_at')->pluck('qc_device_id')->unique()->sort();
        $devices = QcDevice::query()->whereKey($deviceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $inspections = QcInspection::query()->whereIn('device_id', $deviceIds)->orderBy('device_id')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($items as $item) {
            if (! $this->requiresQc($item)) {
                continue;
            }
            foreach ($item->qcAssignments as $assignment) {
                if ($assignment->released_at !== null) {
                    continue;
                }
                $assignment->setRelation('device', $devices[$assignment->qc_device_id]);
                $assignment->certificate->setRelation('device', $devices[$assignment->qc_device_id]);
                $assignment->certificate->setRelation('inspection', $inspections[$assignment->certificate->inspection_id]);
            }
        }
        $readiness = $this->readiness($order, $items, locked: true);
        if ($readiness['status'] !== 'ready') {
            $messages = collect($readiness['lines'])->filter(fn ($l) => $l['required'] && $l['status'] !== 'ready')
                ->map(fn ($l) => $l['product'].' — '.$l['valid'].' / '.$l['required'].' QC units valid/assigned. '.implode(' ', $l['errors']))->join(' ');
            throw ValidationException::withMessages(['qc' => 'Cannot ship '.$order->reference.'. Renewed QC incomplete: '.$messages]);
        }
    }

    public function scan(Order $order, int $itemId, string $code, User $actor): array
    {
        $this->authorizeQueue($actor);
        app(OrderAuthorization::class)->authorize($actor, OrderPermission::View, $order);
        app(QcAuthorization::class)->authorize($actor, QcPermission::ScanDispatch);

        return DB::transaction(function () use ($order, $itemId, $code, $actor): array {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            app(OrderAuthorization::class)->authorize($actor, OrderPermission::View, $order);
            app(QcAuthorization::class)->authorize($actor, QcPermission::ScanDispatch);
            $item = $order->items()->lockForUpdate()->find($itemId);
            if ($order->status !== OrderStatus::Reserved || ! $item || ! $this->requiresQc($item)) {
                throw ValidationException::withMessages(['order_item_id' => 'Select a Renewed item on this Reserved Order.']);
            }
            $service = app(QcOrderAssignmentService::class);
            $certificate = $service->resolveScan($item, $code, $actor, dispatch: true);
            $already = $item->qcAssignments()->active()->where('qc_certificate_id', $certificate->id)->exists();
            $assignment = $service->assign($item, $certificate->id, $actor);
            if (! $already) {
                app(ActivityLogger::class)->log('qc.dispatch_scanned', $actor, $assignment, ['order_reference' => $order->reference, 'qc_reference' => $certificate->snapshot['reference'], 'certificate_version' => $certificate->version]);
            }

            return ['device' => $this->certificateData($assignment->certificate, $assignment), 'readiness' => $this->readiness($order)];
        }, 5);
    }

    public function certificateData($certificate, $assignment = null): array
    {
        $s = $certificate->snapshot;
        $clock = app(BusinessTimezone::class);

        return ['qc_id' => $s['reference'], 'serial' => $s['serial'], 'version' => $certificate->version, 'product' => $s['product']['title'],
            'final_configuration' => array_intersect_key($s['final'], array_flip(['cpu', 'ram_mb', 'storage_gb', 'os'])), 'grade' => $s['grade'],
            'warehouse_id' => $certificate->inspection->warehouse_id, 'certified_at' => $clock->iso($certificate->certified_at),
            'created_at' => $clock->iso($certificate->inspection->created_at), 'completed_at' => $clock->iso($certificate->inspection->completed_at),
            'assigned_at' => $clock->iso($assignment?->assigned_at), 'released_at' => $clock->iso($assignment?->released_at), 'current' => $certificate->isCurrent()];
    }

    public function detail(Order $order, User $actor): array
    {
        $this->authorizeQueue($actor);
        app(OrderAuthorization::class)->authorize($actor, OrderPermission::View, $order);
        $data = ['order_id' => $order->id, 'reference' => $order->reference, 'external_order_number' => $order->external_order_number,
            'warehouse' => $order->warehouse->name, 'order_date' => $order->order_date->toDateString(), 'order_status' => $order->status->value,
            'created_at' => app(BusinessTimezone::class)->iso($order->created_at),
            'readiness' => $this->readinessForViewer($order, $actor), 'timezone' => app(BusinessTimezone::class)->name()];
        $data['devices'] = app(QcAuthorization::class)->allows($actor, QcPermission::ViewOrderAssignments)
            ? $this->items($order)->flatMap->qcAssignments->map(fn ($a) => ['order_item_id' => $a->order_item_id, 'released' => $a->released_at !== null] + $this->certificateData($a->certificate, $a))->values()->all() : [];

        return $data;
    }

    private function readinessForViewer(Order $order, User $actor): array
    {
        $readiness = $this->readiness($order);
        if (! app(QcAuthorization::class)->allows($actor, QcPermission::ViewOrderAssignments)) {
            foreach ($readiness['lines'] as &$line) {
                $line['errors'] = [];
            }
            unset($line);
        }

        return $readiness;
    }

    public function ship(Order $order, string $key, User $actor): Order
    {
        // No outer transaction: OrderService must reserve reference sequences first.
        $this->authorizeQueue($actor);
        app(OrderAuthorization::class)->authorize($actor, OrderPermission::Fulfill, $order);
        app(QcAuthorization::class)->authorize($actor, QcPermission::ShipDispatch);

        return app(OrderService::class)->fulfill($order, $key, $actor);
    }

    public function bulkShip(array $requests, User $actor): array
    {
        $this->authorizeQueue($actor);
        $input = Validator::make(['orders' => $requests], ['orders' => ['required', 'array', 'min:1', 'max:50'], 'orders.*.order_id' => ['required', 'integer', 'distinct'], 'orders.*.idempotency_key' => ['required', 'uuid', 'distinct']])->validate();
        $results = [];
        foreach ($input['orders'] as $row) {
            $order = Order::query()->find($row['order_id']);
            $visible = $order && app(OrderAuthorization::class)->allows($actor, OrderPermission::View, $order);
            $result = ['order_id' => $row['order_id'], 'reference' => $visible ? $order->reference : null];
            try {
                if (! $visible) {
                    throw new AuthorizationException;
                }
                $this->ship($order, $row['idempotency_key'], $actor);
                $result += ['status' => 'shipped', 'message' => 'Order shipped.'];
            } catch (ValidationException $e) {
                $result += ['status' => isset($e->errors()['qc']) ? 'not_ready' : 'failed', 'message' => collect($e->errors())->flatten()->first()];
            } catch (AuthorizationException) {
                $result += ['status' => 'failed', 'message' => 'You do not have permission to ship this Order.'];
            } catch (InvalidOrderTransitionException $e) {
                $result += ['status' => 'failed', 'message' => $e->getMessage()];
            } catch (\Throwable $e) {
                report($e);
                $result += ['status' => 'failed', 'message' => 'Order shipment was not completed. Review stock/reservations and retry.'];
            }
            $results[] = $result;
        }

        return $results;
    }
}
