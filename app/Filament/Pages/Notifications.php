<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Notifications\AppUpdateNotificationService;
use App\Services\Notifications\InventoryAlertPreferenceService;
use App\Services\Notifications\NotificationInboxService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class Notifications extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.notifications';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|\UnitEnum|null $navigationGroup = 'Workspace';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $title = 'Notifications';

    #[Url]
    public string $filter = 'all';

    public bool $receiveInventoryAlerts = true;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->employee?->status === true;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $count = app(NotificationInboxService::class)->unreadCount(auth()->user());

        return $count === 0 ? null : ($count > 99 ? '99+' : (string) $count);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->receiveInventoryAlerts = app(InventoryAlertPreferenceService::class)->enabled($this->user());
    }

    public function canManageInventoryAlerts(): bool
    {
        return app(InventoryAlertPreferenceService::class)->canManage($this->user());
    }

    public function saveInventoryAlertPreference(): void
    {
        $this->validate(['receiveInventoryAlerts' => ['required', 'boolean']]);
        app(InventoryAlertPreferenceService::class)->set($this->user(), $this->receiveInventoryAlerts);
        FilamentNotification::make()->success()->title('Inventory alert preference saved')->send();
    }

    protected function getHeaderActions(): array
    {
        $canSend = fn (): bool => ($user = auth()->user()) instanceof User
            && app(AppUpdateNotificationService::class)->canSend($user);

        return [
            Action::make('sendAppUpdateAlert')
                ->label('Send App Update Alert')
                ->icon(Heroicon::OutlinedMegaphone)
                ->visible($canSend)
                ->authorize($canSend)
                ->schema([
                    Select::make('platform')
                        ->label('Platform / Audience')
                        ->options([
                            'all' => 'All Mobile Devices',
                            'android' => 'Android',
                            'ios' => 'iOS',
                        ])
                        ->default('all')
                        ->required(),
                    Select::make('release_type')
                        ->label('Update Type')
                        ->options([
                            'app_build' => 'App Build',
                            'ota_update' => 'OTA Update',
                            'general_mobile_update' => 'General Mobile Update',
                        ])
                        ->default('general_mobile_update')
                        ->required(),
                    TextInput::make('title')
                        ->default('Tech Point Zone Update Available')
                        ->maxLength(120),
                    Textarea::make('message')
                        ->required()
                        ->maxLength(240)
                        ->rows(3),
                    TextInput::make('release_label')
                        ->label('Release label')
                        ->maxLength(80),
                ])
                ->requiresConfirmation()
                ->modalHeading('Send App Update Alert')
                ->modalDescription('One notification will be created per eligible employee. Push delivery uses the existing queue.')
                ->modalSubmitActionLabel('Send Alert')
                ->action(function (array $data): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    $result = app(AppUpdateNotificationService::class)->send($user, $data);

                    if ($result['duplicate']) {
                        FilamentNotification::make()
                            ->info()
                            ->title('This alert was already submitted')
                            ->body('Wait briefly before submitting the same alert again.')
                            ->send();

                        return;
                    }

                    if (! $result['sent']) {
                        FilamentNotification::make()
                            ->warning()
                            ->title('No eligible mobile devices found')
                            ->body('No active employee with a matching registered mobile device was available.')
                            ->send();

                        return;
                    }

                    FilamentNotification::make()
                        ->success()
                        ->title('App update alert queued')
                        ->body($result['recipient_user_count'].' employees · '.$result['target_device_count'].' devices')
                        ->send();
                }),
        ];
    }

    public function setFilter(string $filter): void
    {
        abort_unless(in_array($filter, ['all', 'unread', 'read'], true), 404);
        $this->filter = $filter;
        $this->resetPage();
    }

    public function markRead(string $id): void
    {
        app(NotificationInboxService::class)->markRead($this->user(), $id);
    }

    public function markAllRead(): void
    {
        app(NotificationInboxService::class)->markAllRead($this->user());
    }

    public function open(string $id): void
    {
        $target = app(NotificationInboxService::class)->open($this->user(), $id);
        if ($target['available']) {
            $this->redirect($target['url'], navigate: true);

            return;
        }

        FilamentNotification::make()
            ->warning()
            ->title('This record is no longer available to you.')
            ->send();
    }

    public function getViewData(): array
    {
        $user = $this->user();
        $service = app(NotificationInboxService::class);
        $query = $service->query($user);

        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->filter === 'read') {
            $query->whereNotNull('read_at');
        }

        $notifications = $query->paginate(20);

        return [
            'notifications' => $notifications,
            'notificationMessages' => $service->displayMessages($notifications->getCollection(), $user),
            'allCount' => $user->notifications()->count(),
            'unreadCount' => $user->unreadNotifications()->count(),
            'readCount' => $user->readNotifications()->count(),
        ];
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }
}
