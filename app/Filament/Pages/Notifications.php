<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Notifications\NotificationInboxService;
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

    protected static string|\UnitEnum|null $navigationGroup = 'Work';

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $title = 'Notifications';

    #[Url]
    public string $filter = 'all';

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
