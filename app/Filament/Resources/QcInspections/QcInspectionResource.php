<?php

namespace App\Filament\Resources\QcInspections;

use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Filament\Resources\QcInspections\Pages\CreateQcInspection;
use App\Filament\Resources\QcInspections\Pages\EditQcInspection;
use App\Filament\Resources\QcInspections\Pages\ListQcInspections;
use App\Filament\Resources\QcInspections\Pages\ViewQcInspection;
use App\Models\Employee;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\QcCertificate;
use App\Models\QcInspection;
use App\Models\Warehouse;
use App\Services\Authorization\QcAuthorization;
use App\Services\Qc\LaptopQcTemplate;
use App\Services\Qc\QcInspectionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class QcInspectionResource extends Resource
{
    protected static ?string $model = QcInspection::class;

    protected static ?string $modelLabel = 'QC Inspection';

    protected static ?string $navigationLabel = 'Quality Control';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 8;

    public static function canViewAny(): bool
    {
        return auth()->user() && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::View);
    }

    public static function canCreate(): bool
    {
        return auth()->user() && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::Start);
    }

    public static function canView($record): bool
    {
        return auth()->user() && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::View, $record);
    }

    public static function canEdit($record): bool
    {
        return $record->status !== QcInspectionStatus::Completed && auth()->user() && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::Update, $record);
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        if (! auth()->user()) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return app(QcInspectionService::class)->visible(auth()->user())->select('qc_inspections.*')
            ->addSelect(['latest_certificate_version' => QcCertificate::query()->selectRaw('MAX(version)')->whereColumn('device_id', 'qc_inspections.device_id')])
            ->addSelect(['active_reinspection_version' => QcInspection::query()->from('qc_inspections as active_qc')->select('active_qc.version')->whereColumn('active_qc.active_device_id', 'qc_inspections.device_id')->limit(1)])
            ->with(['device', 'technician.employee', 'warehouse', 'order', 'certificate']);
    }

    public static function form(Schema $schema): Schema
    {
        $record = $schema->getRecord();
        if (! $record) {
            return $schema->components([
                Section::make('Start Laptop QC')->description('One physical device per Serial / IMEI. QC never changes stock ownership or quantities.')->schema([
                    Select::make('product_id')->label('Product / SKU')->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Product::query()->active()->products()->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('sku', 'like', '%'.$search.'%'))->limit(20)->get()->mapWithKeys(fn (Product $product) => [$product->id => $product->sku.' · '.$product->name])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($product = Product::query()->find($value)) ? $product->sku.' · '.$product->name : null)->live(),
                    TextInput::make('serial')->label('Serial / IMEI')->required()->minLength(3)->maxLength(100),
                    Select::make('warehouse_id')->label('Location')->options(fn () => Warehouse::query()->active()->pluck('name', 'id'))->required()->searchable(),
                    Select::make('order_item_id')->label('Order / Item (optional)')->searchable()
                        ->getSearchResultsUsing(fn (string $search, Get $get): array => OrderItem::query()->with('order')->where('product_id', $get('product_id'))->whereHas('order', fn ($query) => $query->where('reference', 'like', '%'.$search.'%')->orWhere('external_order_number', 'like', '%'.$search.'%'))->limit(20)->get()->mapWithKeys(fn ($item) => [$item->id => $item->order->reference.' · Line '.$item->line_number])->all())
                        ->getOptionLabelUsing(fn ($value): ?string => ($item = OrderItem::query()->with('order')->find($value)) ? $item->order->reference.' · Line '.$item->line_number : null),
                    CheckboxList::make('features')->label('Equipment present / applicable checks')->options(app(LaptopQcTemplate::class)->features())->columns(['default' => 1, 'md' => 2])->default(['backlight', 'usb_a', 'usb_c', 'hdmi', 'headphone', 'camera_indicator'])->helperText('Confirm equipped features. Unequipped checks are recorded as N/A, not silently passed.'),
                    Textarea::make('special_requirement')->label('Upgrade / Special Requirement (optional)')->maxLength(500)->helperText('Structured Order upgrades are loaded automatically. Do not enter costs or customer personal data.'),
                ])->columns(['default' => 1, 'lg' => 2])->columnSpanFull(),
            ]);
        }
        $sections = [
            ViewEntry::make('inspection_context')->view('qc.inspection-context')->columnSpanFull(),
            Section::make('Final Tested Configuration')->description('Enter the actual detected configuration, not the original or requested specification.')->schema([
                TextInput::make('final_configuration.cpu')->label('Detected Processor')->maxLength(255),
                TextInput::make('final_configuration.ram_mb')->label('Detected RAM (MB)')->numeric()->live(onBlur: true)->helperText('8 GB = 8192 MB; 16 GB = 16384 MB'),
                TextInput::make('final_configuration.storage_gb')->label('Detected Storage (GB)')->numeric()->live(onBlur: true),
                TextInput::make('final_configuration.os')->label('Installed OS')->maxLength(120),
            ])->columns(['default' => 1, 'md' => 2])->columnSpanFull(),
        ];
        $upgradePreview = app(QcInspectionService::class)->hasUpgrade($record, $schema->getLivewire()->data['final_configuration'] ?? $record->final_configuration);
        foreach ($record->checks()->get()->filter(fn ($check) => $check->applicable || ($upgradePreview && $check->definition['upgrade']))->groupBy(fn ($check) => $check->definition['group']) as $group => $checks) {
            $fields = [];
            foreach ($checks as $check) {
                $definition = $check->definition;
                $fields[] = Grid::make(['default' => 1, 'lg' => 3])->schema([
                    Radio::make('checks.'.$check->check_key.'.result')->label($definition['label'])->inline()->options(['pass' => 'Pass', 'fail' => 'Fail'] + ($definition['allows_na'] ? ['na' => 'N/A'] : []))->live(),
                    TextInput::make('checks.'.$check->check_key.'.measurement')->label('Measurement')->numeric()->visible($definition['measurement'] !== null)->helperText('Required for Pass; never auto-filled by Pass All.'),
                    TextInput::make('checks.'.$check->check_key.'.notes')->label('Exception / defect notes')->maxLength(2000)->helperText('Required for Fail; internal only.'),
                ]);
            }
            $sections[] = Section::make($group)->collapsible()->collapsed()->headerActions([
                Action::make('pass_'.str($group)->slug())->label('Pass All')->action(fn ($livewire) => $livewire->passGroup($group)),
            ])->schema($fields)->columnSpanFull();
        }
        $sections[] = ViewEntry::make('critical_evidence_status')->view('qc.evidence-status')->columnSpanFull();
        $sections[] = Section::make('Grade & Remarks')->schema([
            Select::make('grade')->options(['A' => 'A', 'B' => 'B', 'C' => 'C']),
            Textarea::make('public_remarks')->label('Customer-visible remarks')->maxLength(2000)->helperText('No costs, customer personal data or internal notes.'),
            Textarea::make('internal_remarks')->label('Internal remarks (never public)')->maxLength(4000),
        ])->columnSpanFull();

        return $schema->components($sections);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([ViewEntry::make('summary')->view('qc.internal-summary')->columnSpanFull()]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('device.reference')->label('QC ID')->searchable(), TextColumn::make('device.serial')->label('Serial / IMEI')->searchable(),
            TextColumn::make('product_snapshot.title')->label('Product')->wrap()->searchable(query: fn (Builder $query, string $search) => $query->where(fn ($match) => $match->where('product_snapshot->title', 'like', '%'.$search.'%')->orWhere('product_snapshot->sku', 'like', '%'.$search.'%'))),
            TextColumn::make('order_snapshot.reference')->label('Order')->searchable(query: fn (Builder $query, string $search) => $query->where('order_snapshot->reference', 'like', '%'.$search.'%')), TextColumn::make('technician.employee.name')->label('Technician'),
            TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => str($state->value)->headline()), TextColumn::make('version')->label('Version'),
            TextColumn::make('validity')->state(fn (QcInspection $record): string => $record->status !== QcInspectionStatus::Completed ? 'Not certified' : ($record->active_reinspection_version !== null ? 'Reinspection pending' : ($record->version === (int) $record->latest_certificate_version ? 'Current / Valid' : 'Superseded')))->badge(),
            TextColumn::make('grade'), TextColumn::make('completed_at')->dateTime('d M Y H:i'),
        ])->filters([
            SelectFilter::make('status')->options(collect(QcInspectionStatus::cases())->mapWithKeys(fn ($state) => [$state->value => str($state->value)->headline()->toString()])->all()),
            SelectFilter::make('grade')->options(['A' => 'A', 'B' => 'B', 'C' => 'C']),
            SelectFilter::make('warehouse_id')->label('Location')->relationship('warehouse', 'name'),
            SelectFilter::make('technician_user_id')->label('Technician')->options(fn () => Employee::query()->whereIn('user_id', app(QcInspectionService::class)->visible(auth()->user())->select('technician_user_id'))->pluck('name', 'user_id')->all())->searchable(),
            SelectFilter::make('condition')->options(['new' => 'New', 'renewed' => 'Renewed'])->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($query, $value) => $query->where('product_snapshot->condition', $value))),
            Filter::make('date')->schema([DatePicker::make('from'), DatePicker::make('until')])->query(fn ($query, array $data) => $query->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))),
        ])->recordActions([ViewAction::make()])->toolbarActions([
            BulkAction::make('printSelectedLabels')->label('Print Selected Labels')->url(fn (Collection $records): string => route('qc.labels', ['ids' => $records->modelKeys()]))->openUrlInNewTab()->visible(fn () => app(QcAuthorization::class)->allows(auth()->user(), QcPermission::PrintLabel)),
        ])->checkIfRecordIsSelectableUsing(fn (QcInspection $record): bool => $record->status === QcInspectionStatus::Completed && $record->version === (int) $record->latest_certificate_version && $record->active_reinspection_version === null && app(QcAuthorization::class)->allows(auth()->user(), QcPermission::PrintLabel, $record))->maxSelectableRecords(50)->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListQcInspections::route('/'), 'create' => CreateQcInspection::route('/create'), 'view' => ViewQcInspection::route('/{record}'), 'edit' => EditQcInspection::route('/{record}/edit')];
    }
}
