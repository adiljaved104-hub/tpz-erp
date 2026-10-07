<?php

namespace App\Filament\Concerns;

use App\Enums\QcPermission;
use App\Models\OrderItem;
use App\Models\QcOrderAssignment;
use App\Services\Authorization\QcAuthorization;
use App\Services\Qc\QcOrderAssignmentService;
use App\Services\Qc\RenewedQcDispatchService;
use App\Services\Qc\RenewedQcRequirement;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait InteractsWithQcOrderAssignments
{
    use HandlesActionFeedback;

    protected function qcOrderActions(): array
    {
        return [
            Action::make('assignQcDevice')->label('Assign QC Device')->icon('heroicon-o-qr-code')
                ->visible(fn (): bool => app(QcOrderAssignmentService::class)->allows(auth()->user(), QcPermission::AssignOrderDevice, $this->record)
                    && app(QcOrderAssignmentService::class)->canChange($this->record)
                    && app(RenewedQcRequirement::class)->orderHasRequiredLine($this->record))
                ->modalDescription('Assign one certified physical unit. No stock, allocation or reservation is changed.')
                ->schema([
                    Select::make('order_item_id')->label('Order Item')->required()->searchable()->live()
                        ->rules([Rule::exists('order_items', 'id')->where('order_id', $this->record->id)])
                        ->options(fn (): array => $this->record->items()->with('product')->get()
                            ->filter(fn ($item): bool => app(RenewedQcRequirement::class)->requires($item))
                            ->mapWithKeys(fn ($item): array => [$item->id => $item->sku.' · '.$item->product_name.' · Qty '.$item->ordered_quantity])->all())
                        ->afterStateUpdated(fn (Set $set) => $set('certificate_id', null)),
                    View::make('qc.assignment-scanner'),
                    Select::make('certificate_id')->label('QC Device / Certificate')->required()->searchable()
                        ->helperText('Search Serial / IMEI or QC ID. Only eligible current devices at this fulfilment location are shown.')
                        ->getSearchResultsUsing(function (string $search, Get $get): array {
                            if (! $get('order_item_id')) {
                                return [];
                            }
                            $item = $this->record->items()->find((int) $get('order_item_id'));
                            if (! $item) {
                                return [];
                            }
                            $service = app(QcOrderAssignmentService::class);

                            return $service->candidates($item, auth()->user(), $search)
                                ->limit(20)->get()->mapWithKeys(fn ($certificate): array => [$certificate->id => $service->label($certificate)])->all();
                        })
                        ->getOptionLabelUsing(function ($value, Get $get): ?string {
                            if (! $value || ! $get('order_item_id')) {
                                return null;
                            }
                            $item = $this->record->items()->find((int) $get('order_item_id'));
                            if (! $item) {
                                return null;
                            }
                            $service = app(QcOrderAssignmentService::class);
                            $certificate = $service->candidates($item, auth()->user())->find($value);

                            return $certificate ? $service->label($certificate) : null;
                        }),
                ])
                ->action(function (array $data): void {
                    $this->runQcAssignmentAction(fn () => app(QcOrderAssignmentService::class)->assign($this->qcOrderItem((int) $data['order_item_id']), (int) $data['certificate_id'], auth()->user()), 'QC device assigned');
                }),
            Action::make('releaseQcAssignment')->label('Release QC Assignment')->icon('heroicon-o-arrow-uturn-left')
                ->visible(fn (): bool => app(QcOrderAssignmentService::class)->allows(auth()->user(), QcPermission::ReleaseOrderAssignment, $this->record)
                    && app(QcOrderAssignmentService::class)->canChange($this->record, release: true)
                    && $this->record->items()->whereHas('qcAssignments', fn ($q) => $q->active())->exists())
                ->modalDescription('The assignment remains in history. Stock and reservations remain unchanged.')
                ->schema([
                    Select::make('assignment_id')->label('Assigned Device')->required()->searchable()
                        ->options(fn (): array => $this->record->items()->with(['qcAssignments' => fn ($q) => $q->active()->with('device')])->get()
                            ->flatMap(fn ($item) => $item->qcAssignments)->mapWithKeys(fn ($assignment): array => [$assignment->id => $assignment->device->reference.' · v'.$assignment->certificate_version.' · '.$assignment->device->serial])->all()),
                    Textarea::make('release_reason')->label('Reason for release')->required()->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $this->runQcAssignmentAction(function () use ($data) {
                        $assignment = QcOrderAssignment::query()->where('order_id', $this->record->id)->find($data['assignment_id']);
                        if (! $assignment) {
                            throw ValidationException::withMessages(['assignment_id' => 'Select a device assignment belonging to this Order.']);
                        }

                        return app(QcOrderAssignmentService::class)->release($assignment, $data['release_reason'], auth()->user());
                    }, 'QC assignment released');
                }),
        ];
    }

    protected function qcDispatchReady(): bool
    {
        $readiness = app(RenewedQcDispatchService::class)->readiness($this->record);

        return $readiness['status'] === 'not_required' || ($readiness['status'] === 'ready'
            && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::ShipDispatch));
    }

    public function resolveQcAssignmentScan(string $value): bool
    {
        $result = $this->runWithActionFeedback(fn () => $this->withQcFieldErrors(function () use ($value) {
            if ($this->getMountedAction()?->getName() !== 'assignQcDevice') {
                throw ValidationException::withMessages(['certificate_id' => 'Open Assign QC Device and select an Order Item first.']);
            }
            $schema = $this->getMountedActionSchema();
            $state = (array) $schema->getRawState();
            $certificate = app(QcOrderAssignmentService::class)->resolveScan($this->qcOrderItem((int) ($state['order_item_id'] ?? 0)), $value, auth()->user());
            $schema->fill([...$state, 'certificate_id' => $certificate->id]);

            return true;
        }), 'QC device could not be selected');

        return $result === true;
    }

    private function qcOrderItem(int $id): OrderItem
    {
        $item = $this->record->items()->find($id);
        if (! $item) {
            throw ValidationException::withMessages(['order_item_id' => 'Select an Order Item belonging to this Order.']);
        }

        return $item;
    }

    private function runQcAssignmentAction(Closure $action, string $success): void
    {
        $result = $this->runWithActionFeedback(fn () => $this->withQcFieldErrors($action), 'QC assignment was not completed');
        if ($result === null) {
            throw new Halt;
        }
        $this->record->refresh();
        Notification::make()->success()->title($success)->send();
    }

    private function withQcFieldErrors(Closure $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            $path = $this->getMountedActionSchema()?->getStatePath();
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $field): array => [filled($path) ? $path.'.'.$field : $field => $messages])->all());
        }
    }
}
