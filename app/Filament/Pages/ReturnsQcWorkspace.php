<?php

namespace App\Filament\Pages;

use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\MarketplaceReturnRemovals\MarketplaceReturnRemovalResource;
use App\Filament\Resources\QcInspections\QcInspectionResource;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Enums\CustomerReturnPermission;
use App\Exceptions\CustomerReturnException;
use App\Models\CustomerReturn;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Returns\CustomerReturnUnifiedWorkflowService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

class ReturnsQcWorkspace extends BaseOperationalWorkspace
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Returns & Service';

    protected static ?string $navigationLabel = 'Return & QC';

    protected static ?string $slug = 'returns-qc-workspace';

    #[Locked]
    public ?string $returnSubmissionKey = null;

    public function mount(): void
    {
        parent::mount();
        $this->returnSubmissionKey = (string) Str::uuid();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createCustomerReturn')
                ->label('Create Customer Return')
                ->modalHeading('Create Customer Return')
                ->modalWidth('6xl')
                ->extraModalWindowAttributes(['class' => 'tpz-return-workspace-modal'])
                ->modalSubmitAction(false)
                ->extraModalFooterActions(fn (Action $action): array => [
                    $action->makeModalSubmitAction('savePending', arguments: ['complete' => false])
                        ->label('Save Pending'),
                    $action->makeModalSubmitAction('receiveAndQc', arguments: ['complete' => true])
                        ->label('Receive & Complete QC')
                        ->color('success')
                        ->visible(fn (): bool => app(CustomerReturnAuthorization::class)->allows(auth()->user(), CustomerReturnPermission::Receive)
                            && app(CustomerReturnAuthorization::class)->allows(auth()->user(), CustomerReturnPermission::Inspect)),
                ])
                ->model(CustomerReturn::class)
                ->visible(fn (): bool => static::canAccess() && CustomerReturnResource::canCreate())
                ->schema(fn (Schema $schema): Schema => CustomerReturnResource::form($schema))
                ->action(function (array $data, array $arguments): void {
                    abort_unless(static::canAccess() && CustomerReturnResource::canCreate(), 403);
                    $complete = (bool) ($arguments['complete'] ?? false);
                    if ($complete) {
                        abort_unless(app(CustomerReturnAuthorization::class)->allows(auth()->user(), CustomerReturnPermission::Receive)
                            && app(CustomerReturnAuthorization::class)->allows(auth()->user(), CustomerReturnPermission::Inspect), 403);
                    }

                    try {
                        $record = app(CustomerReturnUnifiedWorkflowService::class)->submit(
                            $data, auth()->user(), $complete, $this->returnSubmissionKey ?? (string) Str::uuid(),
                        );
                    } catch (CustomerReturnException $exception) {
                        Notification::make()->danger()->title('Cannot process return')
                            ->body($exception->getMessage())->send();

                        throw new Halt;
                    }

                    $this->returnSubmissionKey = (string) Str::uuid();
                    Notification::make()->success()
                        ->title($complete ? 'Return received and QC recorded' : 'Return saved pending')
                        ->body('Return '.($record->reference ?? '#'.$record->id).' created.')->send();
                }),
        ];
    }

    protected static function metricResources(): array
    {
        return [
            ['label' => 'Customer Returns', 'class' => CustomerReturnResource::class, 'icon' => 'heroicon-o-arrow-uturn-left'],
            ['label' => 'QC Inspections', 'class' => QcInspectionResource::class, 'icon' => 'heroicon-o-clipboard-document-check'],
        ];
    }

    protected static function recentResources(): array
    {
        return [CustomerReturnResource::class];
    }

    protected static function workspaceDefinition(): array
    {
        return [
            'title' => 'Returns & QC Workspace',
            'eyebrow' => 'Evidence & quality operations',
            'description' => 'Customer returns, inspections, claims and repairs using existing custody and approval workflows.',
            'icon' => 'heroicon-o-squares-2x2',
            'sections' => [
                [
                    'title' => 'Returns & evidence',
                    'description' => 'Review authorized returns and marketplace recovery workflows',
                    'links' => [
                        ['label' => 'Customer Returns', 'class' => CustomerReturnResource::class, 'icon' => 'heroicon-o-arrow-uturn-left'],
                        ['label' => 'Marketplace Return Removals', 'class' => MarketplaceReturnRemovalResource::class, 'icon' => 'heroicon-o-truck'],
                        ['label' => 'Safe-T Claims', 'class' => SafetClaimResource::class, 'icon' => 'heroicon-o-shield-check'],
                    ],
                ],
                [
                    'title' => 'Quality & repair',
                    'description' => 'Existing inspection and service modules',
                    'links' => [
                        ['label' => 'QC Inspections', 'class' => QcInspectionResource::class, 'icon' => 'heroicon-o-clipboard-document-check'],
                        ['label' => 'Internal Repairs', 'class' => InternalRepairResource::class, 'icon' => 'heroicon-o-wrench-screwdriver'],
                        ['label' => 'Warranty Repairs', 'class' => WarrantyRepairResource::class, 'icon' => 'heroicon-o-cog-6-tooth'],
                    ],
                ],
            ],
        ];
    }
}
