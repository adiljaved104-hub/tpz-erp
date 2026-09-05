<?php

namespace App\Filament\Resources\NotificationRules;

use App\Enums\NotificationRulePermission;
use App\Filament\Resources\NotificationRules\Pages\ListNotificationRules;
use App\Models\NotificationRule;
use App\Models\User;
use App\Services\Authorization\NotificationRuleAuthorization;
use App\Services\Notifications\EmailConfigurationService;
use App\Services\Notifications\NotificationRuleCatalog;
use App\Services\Notifications\NotificationRuleService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class NotificationRuleResource extends Resource
{
    protected static ?string $model = NotificationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Notification Rules';

    protected static ?string $modelLabel = 'Notification Rule';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Event')->searchable()->sortable()->description(fn (NotificationRule $record): string => $record->event_key),
                TextColumn::make('category')->badge()->sortable(),
                self::toggle('enabled', 'Enabled'),
                self::toggle('in_app_enabled', 'In-App'),
                self::toggle('email_enabled', 'Email'),
                TextColumn::make('recipient_strategy')->label('Recipient')->formatStateUsing(fn (string $state): string => str($state)->replace('_', ' ')->headline()),
                TextColumn::make('threshold_value')->label('Threshold / Timing')->formatStateUsing(fn ($state, NotificationRule $record): string => self::timingLabel($record)),
                TextColumn::make('updated_at')->label('Updated')->since()->dateTimeTooltip()->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')->options(fn (): array => NotificationRule::query()->orderBy('category')->distinct()->pluck('category', 'category')->all()),
                TernaryFilter::make('enabled'),
            ])
            ->recordActions([
                Action::make('configure')
                    ->label('Edit')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (): bool => self::canManage())
                    ->fillForm(fn (NotificationRule $record): array => [
                        'enabled' => $record->enabled,
                        'in_app_enabled' => $record->in_app_enabled,
                        'email_enabled' => $record->email_enabled,
                        'recipient_strategy' => $record->recipient_strategy,
                        'threshold_value' => $record->threshold_value,
                    ])
                    ->schema([
                        Toggle::make('enabled')->live(),
                        Toggle::make('in_app_enabled')->label('In-App Enabled'),
                        Toggle::make('email_enabled')->label('Email Enabled')->helperText(fn (): ?string => app(EmailConfigurationService::class)->enabled() ? null : 'Email delivery is currently disabled in Email Settings.'),
                        Select::make('recipient_strategy')->label('Recipient Rule')->options(fn (NotificationRule $record): array => app(NotificationRuleCatalog::class)->recipientOptions($record->event_key))->required(),
                        TextInput::make('threshold_value')->label('Notify Before')->numeric()->minValue(0)->maxValue(365)->suffix('days')->visible(fn (NotificationRule $record): bool => $record->event_key === 'warranty.due_soon'),
                    ])
                    ->action(function (NotificationRule $record, array $data): void {
                        try {
                            app(NotificationRuleService::class)->update($record, $data, auth()->user());
                            Notification::make()->success()->title('Notification rule updated')->send();
                        } catch (ValidationException $exception) {
                            throw $exception;
                        }
                    }),
            ])
            ->defaultSort('category')
            ->emptyStateHeading('No notification rules are available');
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(NotificationRuleAuthorization::class)->allows($user, NotificationRulePermission::View);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return self::canManage();
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationRules::route('/')];
    }

    public static function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(NotificationRuleAuthorization::class)->allows($user, NotificationRulePermission::Manage);
    }

    private static function toggle(string $name, string $label): ToggleColumn
    {
        return ToggleColumn::make($name)
            ->label($label)
            ->disabled(fn (): bool => ! self::canManage())
            ->updateStateUsing(function (NotificationRule $record, mixed $state) use ($name): bool {
                $state = (bool) $state;
                $data = [
                    'enabled' => $record->enabled,
                    'in_app_enabled' => $record->in_app_enabled,
                    'email_enabled' => $record->email_enabled,
                    'recipient_strategy' => $record->recipient_strategy,
                    'threshold_value' => $record->threshold_value,
                    $name => $state,
                ];
                app(NotificationRuleService::class)->update($record, $data, auth()->user());

                return $state;
            });
    }

    private static function timingLabel(NotificationRule $record): string
    {
        if ($record->event_key === 'warranty.due_soon') {
            return $record->threshold_value.' days before SLA';
        }
        if ($record->event_key === 'inventory.low_stock') {
            return 'Inventory threshold: '.app(NotificationRuleCatalog::class)->inventoryLowStockThreshold();
        }

        return 'Event transition';
    }
}
