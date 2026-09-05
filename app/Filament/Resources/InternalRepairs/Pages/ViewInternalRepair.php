<?php

namespace App\Filament\Resources\InternalRepairs\Pages;

use App\Enums\TaskLinkedType;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Actions\CreateTaskFromSourceAction;
use App\Filament\Actions\OpenChatDiscussionAction;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Services\ServiceCases\WarrantyRepairLifecycleService;
use App\Services\ServiceCases\WarrantyRepairService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;

class ViewInternalRepair extends ViewRecord
{
    protected static string $resource = InternalRepairResource::class;

    protected function getHeaderActions(): array
    {
        $actions = collect(WarrantyRepairStatus::cases())
            ->filter(fn (WarrantyRepairStatus $status): bool => ! in_array($status, [WarrantyRepairStatus::Received, WarrantyRepairStatus::InspectionPending, WarrantyRepairStatus::UnderInspection, WarrantyRepairStatus::DispatchedBack, WarrantyRepairStatus::Cancelled], true))
            ->map(function (WarrantyRepairStatus $status): Action {
                $action = Action::make('next_'.$status->value)
                    ->label(fn (): string => app(WarrantyRepairLifecycleService::class)->actionLabel($status, $this->record))
                    ->visible(fn (): bool => app(WarrantyRepairLifecycleService::class)->allows($this->record, $status) && auth()->user()->can('warranty_repair.update_status', $this->record));

                if ($status === WarrantyRepairStatus::CannotRepair) {
                    $action->schema([Textarea::make('note')->label($this->record->status === WarrantyRepairStatus::QcPending ? 'QC Failure Reason' : 'Reason')->required()->maxLength(2000)]);
                } elseif ($status === WarrantyRepairStatus::WaitingForParts) {
                    $action->schema([Textarea::make('note')->label('Note')->maxLength(2000)]);
                }

                return $action->action(fn (array $data) => $this->run($status, $data['note'] ?? null));
            })->values()->all();

        array_unshift($actions, EditAction::make()->visible(fn (): bool => InternalRepairResource::canEdit($this->record)));
        $actions[] = CreateTaskFromSourceAction::make(TaskLinkedType::InternalRepair, $this->record);
        array_unshift($actions, OpenChatDiscussionAction::make($this->record));

        return $actions;
    }

    private function run(WarrantyRepairStatus $status, ?string $note = null): void
    {
        try {
            $this->record = app(WarrantyRepairService::class)->transition($this->record, $status, auth()->user(), $note);
            Notification::make()->success()->title('Internal Repair updated')->send();
        } catch (WarrantyRepairException $exception) {
            Notification::make()->danger()->title('Cannot update Internal Repair')->body($exception->getMessage())->send();
            throw new Halt;
        }
    }
}
