<?php

namespace App\Filament\Resources\HrNotices;

use App\Enums\EmployeeRole;
use App\Enums\HrPermission;
use App\Enums\NoticeAudienceType;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\HrNotices\Pages\CreateHrNotice;
use App\Filament\Resources\HrNotices\Pages\ListHrNotices;
use App\Filament\Resources\HrNotices\Pages\ViewHrNotice;
use App\Models\Employee;
use App\Models\HrAcknowledgment;
use App\Models\HrNotice;
use App\Models\NoticeCategory;
use App\Models\NoticeTemplate;
use App\Models\Team;
use App\Services\Authorization\HrRecordAuthorization;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class HrNoticeResource extends Resource
{
    protected static ?string $model = HrNotice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Notices';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Publish Notice')->columns(2)->schema([
                Select::make('notice_category_id')->label('Category')->options(fn (): array => NoticeCategory::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()->placeholder('No Category')->live()
                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                        $templateId = $get('notice_template_id');
                        if (filled($templateId) && ! NoticeTemplate::query()->whereKey($templateId)->where('notice_category_id', $state)->exists()) {
                            $set('notice_template_id', null);
                        }
                    }),
                Select::make('notice_template_id')->label('Template')
                    ->options(fn (Get $get): array => filled($get('notice_category_id'))
                        ? NoticeTemplate::query()->where('notice_category_id', $get('notice_category_id'))->where('status', true)->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->searchable()->disabled(fn (Get $get): bool => blank($get('notice_category_id')))->live()
                    ->helperText('Selecting a Template prefills the Notice. You can edit every prefilled field before publishing.')
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if (blank($state) || ! ($template = NoticeTemplate::query()->whereKey($state)->where('status', true)->first())) {
                            return;
                        }
                        $set('title', $template->default_title);
                        $set('content', $template->default_content);
                        $set('priority', $template->default_priority);
                        $set('acknowledgment_required', $template->default_acknowledgment_required);
                    }),
                Select::make('audience_type')->label('Audience')->options(NoticeAudienceType::class)->live()->required(),
                Select::make('team_id')->label('Team')->options(fn (): array => self::teamOptions())->searchable()
                    ->visible(fn (Get $get): bool => self::enumValue($get('audience_type')) === NoticeAudienceType::Team->value)
                    ->required(fn (Get $get): bool => self::enumValue($get('audience_type')) === NoticeAudienceType::Team->value),
                Select::make('employee_ids')->label('Employees')->multiple()->options(fn (): array => self::employeeOptions())->searchable()
                    ->visible(fn (Get $get): bool => self::enumValue($get('audience_type')) === NoticeAudienceType::Selected->value)
                    ->required(fn (Get $get): bool => self::enumValue($get('audience_type')) === NoticeAudienceType::Selected->value),
                TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                Textarea::make('content')->label('Notice')->required()->rows(7)->columnSpanFull(),
                Select::make('priority')->options(['normal' => 'Normal', 'important' => 'Important'])->default('normal')->required(),
                DateTimePicker::make('published_at')->label('Publish At')->default(now())->seconds(false)->required(),
                DateTimePicker::make('expires_at')->label('Expires At')->seconds(false)->afterOrEqual('published_at'),
                Toggle::make('acknowledgment_required')->label('Acknowledgment Required')->default(false),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Notice')->columns(3)->schema([
                TextEntry::make('reference')->label('Notice Ref'),
                TextEntry::make('priority')->badge(),
                TextEntry::make('status')->badge(),
                TextEntry::make('category.name')->label('Category')->placeholder('No Category'),
                TextEntry::make('audience_label')->label('Audience')->state(fn (HrNotice $record): string => $record->audience_type === NoticeAudienceType::Team ? 'Team — '.$record->team?->name : $record->audience_type->getLabel()),
                TextEntry::make('published_at')->label('Published')->dateTime('d M Y, h:i A'),
                TextEntry::make('expires_at')->label('Expires')->dateTime('d M Y, h:i A')->placeholder('No expiry'),
                TextEntry::make('title')->columnSpanFull(),
                TextEntry::make('content')->label('Notice')->columnSpanFull(),
                TextEntry::make('publishedBy.name')->label('Published By'),
                TextEntry::make('recipient_count')->label('Recipients')->state(fn (HrNotice $record): int => $record->recipients->count()),
                TextEntry::make('read_state')->label('Read State')->state(function (HrNotice $record): string {
                    $ack = $record->acknowledgments->firstWhere('employee_id', auth()->user()?->employee?->id);

                    return $ack?->read_at ? 'Read' : 'Unread';
                })->badge()->visible(fn (HrNotice $record): bool => self::isCurrentEmployeeRecipient($record)),
                TextEntry::make('acknowledgment_state')->label('Your Acknowledgment')->state(function (HrNotice $record): string {
                    if (! $record->acknowledgment_required) {
                        return 'Not Required';
                    }
                    $ack = $record->acknowledgments->firstWhere('employee_id', auth()->user()?->employee?->id);

                    return $ack?->acknowledged_at ? 'Acknowledged' : 'Pending';
                })->badge()->visible(fn (HrNotice $record): bool => self::isCurrentEmployeeRecipient($record)),
                TextEntry::make('acknowledged_at')->label('Acknowledged At')
                    ->state(fn (HrNotice $record) => self::currentEmployeeAcknowledgment($record)?->acknowledged_at)
                    ->dateTime('d M Y, h:i A')
                    ->visible(fn (HrNotice $record): bool => self::isCurrentEmployeeRecipient($record)
                        && self::currentEmployeeAcknowledgment($record)?->acknowledged_at !== null),
                TextEntry::make('read_progress')->label('Read')
                    ->state(fn (HrNotice $record): string => self::recipientProgress($record, 'read_at'))
                    ->visible(fn (HrNotice $record): bool => ! self::isCurrentEmployeeRecipient($record)),
                TextEntry::make('acknowledgment_progress')->label('Acknowledged')
                    ->state(fn (HrNotice $record): string => self::recipientProgress($record, 'acknowledged_at'))
                    ->visible(fn (HrNotice $record): bool => ! self::isCurrentEmployeeRecipient($record) && $record->acknowledgment_required),
            ]),
            Section::make('Recipient Status')->schema([
                RepeatableEntry::make('recipient_status')
                    ->hiddenLabel()
                    ->state(fn (HrNotice $record) => self::recipientStatusRows($record))
                    ->schema([
                        TextEntry::make('employee')->label('Employee')
                            ->formatStateUsing(fn (Employee $state): string => $state->name)
                            ->url(fn (Employee $state, ViewHrNotice $livewire): ?string => $livewire->canLinkRecipientEmployees()
                                ? EmployeeResource::getUrl('view', ['record' => $state])
                                : null),
                        TextEntry::make('read_status')->label('Read Status')->badge()
                            ->color(fn (string $state): string => $state === 'Read' ? 'success' : 'gray'),
                        TextEntry::make('read_at')->label('Read At')->dateTime('d M Y, h:i A')->placeholder('—'),
                        TextEntry::make('acknowledgment_status')->label('Acknowledgment Status')->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'Acknowledged' => 'success',
                                'Pending' => 'warning',
                                default => 'gray',
                            }),
                        TextEntry::make('acknowledged_at')->label('Acknowledged At')->dateTime('d M Y, h:i A')->placeholder('—'),
                    ])
                    ->table([
                        TableColumn::make('Employee'),
                        TableColumn::make('Read Status'),
                        TableColumn::make('Read At'),
                        TableColumn::make('Acknowledgment Status'),
                        TableColumn::make('Acknowledged At'),
                    ])
                    ->columnSpanFull(),
            ])->visible(fn (ViewHrNotice $livewire): bool => $livewire->canViewRecipientStatus()),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->label('Notice Ref')->searchable()->sortable(),
            TextColumn::make('title')->searchable()->limit(50),
            TextColumn::make('category.name')->label('Category')->placeholder('No Category'),
            TextColumn::make('audience_type')->label('Audience')->badge(),
            TextColumn::make('priority')->badge(),
            TextColumn::make('published_at')->label('Published')->dateTime('d M Y, h:i A')->sortable(),
            TextColumn::make('recipients_count')->label('Recipients')->counts('recipients'),
            TextColumn::make('status')->badge(),
        ])->filters([
            SelectFilter::make('audience_type')->label('Audience')->options(NoticeAudienceType::class),
            SelectFilter::make('notice_category_id')->label('Category')->options(fn (): array => NoticeCategory::query()->orderBy('name')->pluck('name', 'id')->all()),
            SelectFilter::make('status')->options(['active' => 'Active', 'archived' => 'Archived']),
        ])->recordActions([ViewAction::make()]);
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && self::authorization()->allows($user, HrPermission::NoticeView);
    }

    public static function shouldRegisterNavigation(): bool
    {
        $user = auth()->user();
        if ($user === null || ! self::canViewAny()) {
            return false;
        }
        if (self::authorization()->allows($user, HrPermission::NoticeManage)
            || self::authorization()->allows($user, HrPermission::NoticePublish)) {
            return true;
        }

        return self::authorization()->scopeNotices(HrNotice::query(), $user)->exists();
    }

    public static function canCreate(): bool
    {
        $user = auth()->user();

        return $user !== null && self::authorization()->allows($user, HrPermission::NoticePublish);
    }

    public static function canView(Model $record): bool
    {
        return auth()->user() !== null && self::authorization()->canViewNotice(auth()->user(), $record);
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $query = parent::getEloquentQuery()->with(['category:id,name,status', 'template:id,name,status', 'team:id,name', 'publishedBy:id,name', 'recipients.employee:id,name,team_id', 'acknowledgments']);

        return $user ? self::authorization()->scopeNotices($query, $user) : $query->whereRaw('1 = 0');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return HrNotice::query();
    }

    public static function getPages(): array
    {
        return ['index' => ListHrNotices::route('/'), 'create' => CreateHrNotice::route('/create'), 'view' => ViewHrNotice::route('/{record}')];
    }

    private static function authorization(): HrRecordAuthorization
    {
        return app(HrRecordAuthorization::class);
    }

    private static function employeeOptions(): array
    {
        $user = auth()->user();
        $query = Employee::query()->where('status', true)->whereNotNull('user_id');
        if (! in_array($user?->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            $query->where('team_id', $user?->employee?->team_id);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    private static function teamOptions(): array
    {
        $user = auth()->user();
        $query = Team::query()->where('status', true);
        if (! in_array($user?->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            $query->whereKey($user?->employee?->team_id);
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }

    private static function enumValue(mixed $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value;
    }

    private static function isCurrentEmployeeRecipient(HrNotice $notice): bool
    {
        $employeeId = auth()->user()?->employee?->id;

        return $employeeId !== null && $notice->recipients->contains('employee_id', $employeeId);
    }

    private static function currentEmployeeAcknowledgment(HrNotice $notice): ?HrAcknowledgment
    {
        return $notice->acknowledgments->firstWhere('employee_id', auth()->user()?->employee?->id);
    }

    private static function recipientProgress(HrNotice $notice, string $timestamp): string
    {
        $recipientIds = $notice->recipients->pluck('employee_id');
        $completed = $notice->acknowledgments
            ->whereIn('employee_id', $recipientIds)
            ->whereNotNull($timestamp)
            ->count();

        return $completed.' / '.$recipientIds->count();
    }

    private static function recipientStatusRows(HrNotice $notice): array
    {
        $acknowledgments = $notice->acknowledgments->keyBy('employee_id');

        return $notice->recipients->map(function ($recipient) use ($notice, $acknowledgments): array {
            $acknowledgment = $acknowledgments->get($recipient->employee_id);
            $employee = $recipient->employee;

            return [
                'employee' => $employee,
                'read_status' => $acknowledgment?->read_at !== null ? 'Read' : 'Unread',
                'read_at' => $acknowledgment?->read_at,
                'acknowledgment_status' => ! $notice->acknowledgment_required
                    ? 'Not Required'
                    : ($acknowledgment?->acknowledged_at !== null ? 'Acknowledged' : 'Pending'),
                'acknowledged_at' => $acknowledgment?->acknowledged_at,
            ];
        })->all();
    }
}
