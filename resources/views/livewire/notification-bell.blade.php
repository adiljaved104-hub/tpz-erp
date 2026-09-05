<div
    wire:poll.45s="refreshNotifications"
    x-data="{
        soundOn: localStorage.getItem('tpz-notification-sound') !== 'off',
        toggleSound() {
            this.soundOn = ! this.soundOn;
            localStorage.setItem('tpz-notification-sound', this.soundOn ? 'on' : 'off');
        },
        play(id) {
            if (! this.soundOn || ! id || sessionStorage.getItem('tpz-notification-played') === id) return;
            sessionStorage.setItem('tpz-notification-played', id);
            try {
                const Context = window.AudioContext || window.webkitAudioContext;
                if (! Context) return;
                const context = new Context();
                const oscillator = context.createOscillator();
                const gain = context.createGain();
                oscillator.frequency.value = 660;
                gain.gain.setValueAtTime(0.04, context.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.16);
                oscillator.connect(gain);
                gain.connect(context.destination);
                oscillator.start();
                oscillator.stop(context.currentTime + 0.16);
            } catch (error) {}
        }
    }"
    x-on:notification-sound.window="play($event.detail.id)"
>
    <x-filament::dropdown placement="bottom-end" width="sm" teleport>
        <x-slot name="trigger">
            <button type="button" class="relative flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 outline-none transition hover:bg-gray-100 hover:text-gray-700 focus-visible:ring-2 focus-visible:ring-primary-500 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-200" aria-label="Notifications">
                <x-filament::icon icon="heroicon-o-bell" class="h-5 w-5" />
                @if ($unreadCount > 0)
                    <span class="absolute -right-1 -top-1 min-w-4 rounded-full bg-danger-600 px-1 text-center text-[10px] font-semibold leading-4 text-white" aria-label="{{ $unreadCount }} unread notifications">
                        {{ $unreadCount > 99 ? '99+' : $unreadCount }}
                    </span>
                @endif
            </button>
        </x-slot>

        <div class="w-80 max-w-[calc(100vw-2rem)]">
            <div class="flex items-center justify-between border-b border-gray-200 px-3 py-2 dark:border-white/10">
                <p class="text-sm font-semibold text-gray-950 dark:text-white">Notifications</p>
                <button type="button" class="text-xs font-medium text-gray-500 hover:text-primary-600" x-on:click="toggleSound" x-text="soundOn ? 'Sound On' : 'Sound Off'" aria-label="Toggle notification sound"></button>
            </div>

            <div class="max-h-96 overflow-y-auto">
                @forelse ($notifications as $notification)
                    <div @class(['border-b border-gray-100 px-3 py-2.5 last:border-0 dark:border-white/5', 'bg-primary-50/50 dark:bg-primary-950/20' => $notification->unread()])>
                        <p class="truncate text-sm font-medium text-gray-950 dark:text-white">{{ data_get($notification->data, 'title', 'Notification') }}</p>
                        <p class="mt-0.5 line-clamp-2 text-xs text-gray-600 dark:text-gray-300">{{ $notificationMessages[(string) $notification->id] ?? data_get($notification->data, 'message') }}</p>
                        <div class="mt-1.5 flex items-center justify-between gap-2">
                            <span class="text-[11px] text-gray-500">{{ $notification->created_at?->diffForHumans() }}</span>
                            <div class="flex items-center gap-2">
                                @if ($notification->unread())
                                    <button type="button" class="text-xs text-gray-500 hover:text-primary-600" wire:click="markRead('{{ $notification->id }}')">Mark read</button>
                                @endif
                                <button type="button" class="text-xs font-semibold text-primary-600 hover:text-primary-500" wire:click="open('{{ $notification->id }}')">Open</button>
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="px-4 py-8 text-center text-sm text-gray-500">You have no notifications.</p>
                @endforelse
            </div>

            <div class="flex items-center justify-between border-t border-gray-200 px-3 py-2 dark:border-white/10">
                @if ($unreadCount > 0)
                    <button type="button" class="text-xs font-medium text-gray-500 hover:text-primary-600" wire:click="markAllRead">Mark all read</button>
                @else
                    <span></span>
                @endif
                <a href="{{ $notificationsUrl }}" wire:navigate class="text-xs font-semibold text-primary-600 hover:text-primary-500">View all</a>
            </div>
        </div>
    </x-filament::dropdown>
</div>
