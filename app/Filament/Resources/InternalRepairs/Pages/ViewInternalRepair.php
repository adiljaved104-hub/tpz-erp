<?php

namespace App\Filament\Resources\InternalRepairs\Pages;

use App\Enums\ProductCondition;
use App\Enums\TaskLinkedType;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Actions\CreateTaskFromSourceAction;
use App\Filament\Actions\OpenChatDiscussionAction;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\Products\ProductResource;
use App\Models\InventoryAllocationAccount;
use App\Services\ServiceCases\RepairStockClassificationService;
use App\Services\ServiceCases\WarrantyRepairLifecycleService;
use App\Services\ServiceCases\WarrantyRepairService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        $actions[] = Action::make('classifyStock')
            ->label('Classify Repaired Stock')
            ->color('warning')
            ->visible(fn (): bool => $this->record->status === WarrantyRepairStatus::PendingClassification
                && app(RepairStockClassificationService::class)->canApprove(auth()->user(), $this->record))
            ->schema([
                Select::make('condition')->label('Final Condition')->options(collect(ProductCondition::cases())
                    ->mapWithKeys(fn (ProductCondition $condition): array => [$condition->value => $condition->label()])->all())->required()->live(),
                Select::make('destination_product_id')->label('Destination SKU')
                    ->options(fn (Get $get): array => $get('condition')
                        ? app(RepairStockClassificationService::class)->destinationOptions($this->record, ProductCondition::from($get('condition')))
                        : [])
                    ->searchable()->required()->helperText('Select the matching condition-specific SKU. If none exists, create it in Products first.'),
                Select::make('allocation_account_id')->label('Allocation Holder')
                    ->options(fn (): array => InventoryAllocationAccount::query()->where('status', true)->where('is_system', false)
                        ->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->helperText('Optional when an original damaged-stock holder is known. Otherwise choose an employee or team explicitly.'),
                Placeholder::make('matching_sku_missing')->label('Matching Condition SKU Not Found')
                    ->content(fn (): HtmlString => new HtmlString('<a class="text-primary-600 underline" href="'.e(ProductResource::getUrl('create')).'">Create the correct product using the existing Product form</a>'))
                    ->visible(fn (Get $get): bool => filled($get('condition')) && ProductResource::canCreate()
                        && app(RepairStockClassificationService::class)->destinationOptions($this->record, ProductCondition::from($get('condition'))) === []),
                Textarea::make('reason')->label('Classification Reason')->required()->minLength(10)->maxLength(2000),
                Checkbox::make('sealed_replacement_confirmed')->label('I verified a genuine sealed new replacement, not an ordinarily repaired item')
                    ->visible(fn (Get $get): bool => $get('condition') === ProductCondition::New->value),
                TextInput::make('replacement_evidence_reference')->label('Sealed Replacement Evidence Reference')
                    ->maxLength(255)->visible(fn (Get $get): bool => $get('condition') === ProductCondition::New->value),
                Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
                Placeholder::make('stock_note')->label('Inventory Safety')->content('QC passed, but the original stock stays damaged and non-saleable until this classification is approved.'),
            ])
            ->action(function (array $data): void {
                try {
                    app(RepairStockClassificationService::class)->approve($this->record, $data, auth()->user());
                    $this->record->refresh();
                    Notification::make()->success()->title('Repaired stock classified')->send();
                } catch (WarrantyRepairException|AuthorizationException|ValidationException $exception) {
                    Notification::make()->danger()->title('Stock was not classified')
                        ->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : $exception->getMessage())->send();
                    throw new Halt;
                }
            });
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
