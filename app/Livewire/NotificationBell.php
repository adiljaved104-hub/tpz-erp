<?php

namespace App\Livewire;

use App\Filament\Pages\Notifications;
use App\Models\User;
use App\Services\Notifications\NotificationInboxService;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\View\View;
use Livewire\Component;

class NotificationBell extends Component
{
    public int $unreadCount = 0;

    public ?string $lastImportantUnreadId = null;

    public function mount(NotificationInboxService $inbox): void
    {
        $user = $this->user();
        $this->unreadCount = $inbox->unreadCount($user);
        $this->lastImportantUnreadId = $inbox->latestImportantUnreadId($user);
    }

    public function refreshNotifications(NotificationInboxService $inbox): void
    {
        $user = $this->user();
        $latest = $inbox->latestImportantUnreadId($user);

        if ($latest !== null && $latest !== $this->lastImportantUnreadId) {
            $this->dispatch('notification-sound', id: $latest);
        }

        $this->lastImportantUnreadId = $latest;
        $this->unreadCount = $inbox->unreadCount($user);
    }

    public function markRead(string $id, NotificationInboxService $inbox): void
    {
        $inbox->markRead($this->user(), $id);
        $this->refreshNotifications($inbox);
    }

    public function markAllRead(NotificationInboxService $inbox): void
    {
        $inbox->markAllRead($this->user());
        $this->refreshNotifications($inbox);
    }

    public function open(string $id, NotificationInboxService $inbox): void
    {
        $target = $inbox->open($this->user(), $id);
        $this->refreshNotifications($inbox);

        if ($target['available']) {
            $this->redirect($target['url'], navigate: true);

            return;
        }

        FilamentNotification::make()->warning()->title('This record is no longer available to you.')->send();
    }

    public function render(NotificationInboxService $inbox): View
    {
        $user = $this->user();
        $notifications = $inbox->query($user)->limit(8)->get();

        return view('livewire.notification-bell', [
            'notifications' => $notifications,
            'notificationMessages' => $inbox->displayMessages($notifications, $user),
            'notificationsUrl' => Notifications::getUrl(),
        ]);
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->employee?->status === true, 403);

        return $user;
    }
}
