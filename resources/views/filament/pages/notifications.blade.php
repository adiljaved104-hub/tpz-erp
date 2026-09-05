<x-filament-panels::page>
    <style>
        .notification-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: center; gap: .75rem; }
        .notification-content { display: flex; min-width: 0; align-items: flex-start; gap: .75rem; }
        .notification-actions { display: flex; align-items: center; justify-content: flex-end; gap: .5rem; }
        @media (max-width: 639px) {
            .notification-row { grid-template-columns: minmax(0, 1fr); }
            .notification-actions { justify-content: flex-start; padding-left: 3rem; }
        }
    </style>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <x-filament::tabs label="Notification filters" contained>
                @foreach (['all' => ['All', $allCount], 'unread' => ['Unread', $unreadCount], 'read' => ['Read', $readCount]] as $value => [$label, $count])
                    <x-filament::tabs.item
                        :active="$filter === $value"
                        wire:click="setFilter('{{ $value }}')"
                    >
                        {{ $label }} <span class="text-xs opacity-70">{{ $count }}</span>
                    </x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>

            @if ($unreadCount > 0)
                <x-filament::button color="gray" size="sm" wire:click="markAllRead">
                    Mark all as read
                </x-filament::button>
            @endif
        </div>

        <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            @forelse ($notifications as $notification)
                <div @class([
                    'notification-row border-b border-gray-200 px-4 py-3 last:border-b-0 dark:border-white/10',
                    'bg-primary-50/50 dark:bg-primary-950/20' => $notification->unread(),
                ])>
                    <div class="notification-content">
                        <span @class(['flex size-9 shrink-0 items-center justify-center rounded-full', 'bg-primary-100 text-primary-700 dark:bg-primary-950/50 dark:text-primary-300' => $notification->unread(), 'bg-gray-100 text-gray-500 dark:bg-white/10' => $notification->read()])>
                            <x-filament::icon icon="heroicon-o-bell" class="size-4" />
                        </span>
                        <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">
                                {{ data_get($notification->data, 'title', 'Notification') }}
                            </p>
                            <x-filament::badge size="sm" :color="$notification->unread() ? 'primary' : 'gray'" :icon="$notification->unread() ? 'heroicon-m-envelope' : 'heroicon-m-envelope-open'">{{ $notification->unread() ? 'Unread' : 'Read' }}</x-filament::badge>
                        </div>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            {{ $notificationMessages[(string) $notification->id] ?? data_get($notification->data, 'message') }}
                        </p>
                        <p class="mt-1 text-xs text-gray-500" title="{{ $notification->created_at?->format('d M Y, h:i A') }}">
                            {{ $notification->created_at?->diffForHumans() }}
                        </p>
                        </div>
                    </div>
                    <div class="notification-actions shrink-0">
                        @if ($notification->unread())
                            <x-filament::button color="gray" size="xs" wire:click="markRead('{{ $notification->id }}')">
                                Mark read
                            </x-filament::button>
                        @endif
                        <x-filament::button size="xs" wire:click="open('{{ $notification->id }}')">
                            Open
                        </x-filament::button>
                    </div>
                </div>
            @empty
                <div class="px-6 py-12 text-center text-sm text-gray-500">
                    You have no {{ $filter === 'all' ? '' : $filter }} notifications.
                </div>
            @endforelse
        </div>

        {{ $notifications->links() }}
    </div>
</x-filament-panels::page>
