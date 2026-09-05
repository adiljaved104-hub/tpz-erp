<?php

namespace App\Filament\Resources\WarrantyRepairs\Pages;

use App\Enums\EmployeeRole;
use App\Enums\TaskLinkedType;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\ReturnRefundException;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Actions\CreateTaskFromSourceAction;
use App\Filament\Actions\OpenChatDiscussionAction;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Services\Returns\ReturnRefundService;
use App\Services\ServiceCases\WarrantyRepairLifecycleService;
use App\Services\ServiceCases\WarrantyRepairService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewWarrantyRepair extends ViewRecord
{
    protected static string $resource = WarrantyRepairResource::class;

    protected function getHeaderActions(): array
    {
        $actions = collect(WarrantyRepairStatus::cases())
            ->filter(fn (WarrantyRepairStatus $status): bool => ! in_array($status, [WarrantyRepairStatus::Received, WarrantyRepairStatus::InspectionPending, WarrantyRepairStatus::Cancelled], true))
            ->map(function (WarrantyRepairStatus $status): Action {
                $action = Action::make('next_'.$status->value)
                    ->label(fn (): string => app(WarrantyRepairLifecycleService::class)->actionLabel($status, $this->record))
                    ->visible(fn (): bool => app(WarrantyRepairLifecycleService::class)->allows($this->record, $status) && auth()->user()->can('warranty_repair.update_status', $this->record));

                if ($status === WarrantyRepairStatus::CannotRepair) {
                    return $action->schema([
                        Textarea::make('note')->label('Reason')->required()->maxLength(2000),
                    ])->action(fn (array $data) => $this->run($status, $data['note']));
                }

                if ($status === WarrantyRepairStatus::WaitingForParts) {
                    return $action->schema([
                        Textarea::make('note')->label('Note')->maxLength(2000),
                    ])->action(fn (array $data) => $this->run($status, $data['note'] ?? null));
                }

                if ($status === WarrantyRepairStatus::DispatchedBack) {
                    return $action->schema([
                        DateTimePicker::make('dispatched_back_at')->label('Dispatch Date')->default(now())->maxDate(now())->validationMessages(['before_or_equal' => 'Dispatch date cannot be in the future.'])->required(),
                        TextInput::make('dispatch_tracking_reference')->label('Tracking / Dispatch Reference')->maxLength(255),
                        Textarea::make('note')->maxLength(2000),
                    ])->action(fn (array $data) => $this->run($status, $data['note'] ?? null, [
                        'dispatched_back_at' => $data['dispatched_back_at'],
                        'dispatch_tracking_reference' => $data['dispatch_tracking_reference'] ?? null,
                    ]));
                }

                return $action->action(fn () => $this->run($status));
            })->all();

        array_unshift($actions, EditAction::make()->visible(fn (): bool => WarrantyRepairResource::canEdit($this->record)));

        array_unshift($actions, Action::make('correctReceivedAt')
            ->label('Correct Received At')
            ->color('gray')
            ->visible(fn (): bool => in_array(auth()->user()?->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
                && auth()->user()->can('warranty_repair.update_status', $this->record))
            ->schema([
                DateTimePicker::make('received_at')->label('Corrected Received At')->default(fn () => $this->record->received_at)->maxDate(now())->required(),
                Textarea::make('reason')->label('Correction Reason')->required()->maxLength(2000),
            ])
            ->action(function (array $data): void {
                try {
                    $this->record = app(WarrantyRepairService::class)->correctReceivedAt($this->record, $data['received_at'], $data['reason'], auth()->user());
                    Notification::make()->success()->title('Received date corrected')->send();
                } catch (WarrantyRepairException|ValidationException|AuthorizationException $exception) {
                    $message = $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first()
                        : ($exception->getMessage() ?: 'You are not authorized to correct this date.');
                    Notification::make()->danger()->title('Received date could not be corrected')->body($message)->send();
                    throw new Halt;
                }
            }));

        $actions[] = Action::make('recordRefund')->label('Record Customer Refund')->color('warning')
            ->visible(fn (): bool => ! $this->record->isInternalCompanyOwnedRepair()
                && $this->record->order_id !== null
                && ($this->record->refund ?? $this->record->customerReturn?->refund) === null
                && auth()->user()->can('return.record_refund'))
            ->schema([
                TextInput::make('refund_amount')->label('Refund Amount')->prefix('AED')->numeric()->required()->minValue(0.01)
                    ->default(fn (): ?string => app(ReturnRefundService::class)->suggestedAmountForWarranty($this->record)),
                DatePicker::make('refund_date')->label('Refund Date')->default(today())->maxDate(today())->required(),
                TextInput::make('external_refund_reference')->label('External Refund Reference')->maxLength(255),
                Textarea::make('note')->label('Note')->maxLength(5000),
            ])->action(function (array $data): void {
                try {
                    app(ReturnRefundService::class)->recordForWarranty(
                        $this->record,
                        (string) $data['refund_amount'],
                        $data['refund_date'],
                        $data['external_refund_reference'] ?? null,
                        $data['note'] ?? null,
                        (string) Str::uuid(),
                        auth()->user(),
                    );
                    $this->record->refresh()->load(['refund', 'customerReturn.refund', 'customerReturn.claims']);
                    Notification::make()->success()->title('Customer refund recorded')->send();
                } catch (ReturnRefundException|ValidationException|AuthorizationException $exception) {
                    $message = $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first()
                        : ($exception->getMessage() ?: 'You are not authorized to record this refund.');
                    Notification::make()->danger()->title('Cannot record customer refund')->body($message)->send();
                    throw new Halt;
                }
            });

        $actions[] = Action::make('moveToDamaged')->label('Move to Damaged Items')->color('danger')->requiresConfirmation()
            ->visible(fn () => $this->record->status === WarrantyRepairStatus::CannotRepair && $this->record->moved_to_damaged_at === null && $this->record->source !== WarrantyRepairSource::DamagedItem && auth()->user()->can('warranty_repair.move_to_damaged', $this->record))
            ->schema([TextInput::make('quantity')->numeric()->minValue(1)->default(fn () => $this->record->quantity)->required(), Select::make('warehouse_id')->label('Company Location')->relationship('warehouse', 'name')->default(fn () => $this->record->warehouse_id)->required(), Textarea::make('reason')->required(), Textarea::make('note')])
            ->action(function (array $data): void {
                try {
                    $this->record = app(WarrantyRepairService::class)->moveToDamaged($this->record, (int) $data['quantity'], (int) $data['warehouse_id'], $data['reason'], $data['note'] ?? null, (string) Str::uuid(), auth()->user());
                    Notification::make()->success()->title('Moved to Damaged Items')->send();
                } catch (WarrantyRepairException $exception) {
                    Notification::make()->danger()->title('Cannot move to Damaged Items')->body($exception->getMessage())->send();
                    throw new Halt;
                }
            });

        $actions[] = CreateTaskFromSourceAction::make(TaskLinkedType::WarrantyRepair, $this->record);
        array_unshift($actions, OpenChatDiscussionAction::make($this->record));

        return $actions;
    }

    private function run(WarrantyRepairStatus $status, ?string $note = null, array $fields = []): void
    {
        try {
            $this->record = app(WarrantyRepairService::class)->transition($this->record, $status, auth()->user(), $note, $fields);
            Notification::make()->success()->title('Warranty / Repair updated')->send();
        } catch (WarrantyRepairException $exception) {
            Notification::make()->danger()->title('Cannot update case')->body($exception->getMessage())->send();
            throw new Halt;
        }
    }
}
