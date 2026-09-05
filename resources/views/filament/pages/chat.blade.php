<x-filament-panels::page>
    <style>
        .chat-workspace { display: grid; grid-template-columns: minmax(0, 1fr); width: 100%; max-width: 100%; }
        .chat-conversation-list, .chat-active-pane { min-width: 0; }
        .chat-conversation-row { display: block; width: 100%; }
        @media (max-width: 1023px) { .chat-mobile-hidden { display: none; } }
        @media (min-width: 1024px) {
            .chat-workspace { grid-template-columns: 19rem minmax(0, 1fr); }
            .chat-conversation-list { border-right: 1px solid color-mix(in srgb, currentColor 14%, transparent); }
        }
    </style>
    <div class="chat-workspace min-h-[70vh] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900 lg:h-[calc(100vh-12rem)]" data-testid="chat-two-pane-layout">
        <aside @class(['chat-conversation-list border-b border-gray-200 dark:border-white/10 lg:flex lg:h-full lg:flex-col lg:border-b-0', 'chat-mobile-hidden' => $selected])>
            <div class="space-y-3 border-b border-gray-200 p-4 dark:border-white/10">
                <x-filament::input.wrapper>
                    <x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Search conversations or messages" aria-label="Search Chat" />
                </x-filament::input.wrapper>
                @if($search !== '')
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 lg:grid-cols-1">
                        <select wire:model.live="searchEmployee" aria-label="Filter messages by sender" class="rounded-lg border-gray-300 text-xs dark:border-white/10 dark:bg-white/5"><option value="">Any sender</option>@foreach($searchEmployeeOptions as $id=>$name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                        <input type="date" wire:model.live="searchFrom" aria-label="Messages from date" class="rounded-lg border-gray-300 text-xs dark:border-white/10 dark:bg-white/5">
                        <input type="date" wire:model.live="searchTo" aria-label="Messages to date" class="rounded-lg border-gray-300 text-xs dark:border-white/10 dark:bg-white/5">
                    </div>
                @endif
                <div class="flex flex-wrap gap-1.5" role="tablist" aria-label="Conversation filters">
                    @foreach (['all' => 'All', 'channels' => 'Channels', 'direct' => 'DMs', 'teams' => 'Teams', 'contexts' => 'Work Discussions', 'archived' => 'Archived'] as $value => $label)
                        <button type="button" role="tab" aria-selected="{{ $filter === $value ? 'true' : 'false' }}" wire:click="setFilter('{{ $value }}')"
                            @class(['whitespace-nowrap rounded-lg px-2.5 py-1.5 text-xs font-medium transition', 'bg-primary-600 text-white shadow-sm' => $filter === $value, 'bg-gray-50 text-gray-600 hover:bg-gray-100 dark:bg-white/5 dark:text-gray-300 dark:hover:bg-white/10' => $filter !== $value])>
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
                @if ($publicChannels->isNotEmpty() && $filter === 'channels')
                    <div class="rounded-lg bg-gray-50 p-2 dark:bg-white/5">
                        <p class="mb-1 text-xs font-semibold text-gray-600 dark:text-gray-300">Browse public channels</p>
                        @foreach ($publicChannels as $channel)
                            <div class="flex items-center justify-between gap-2 py-1 text-xs">
                                <span class="min-w-0 truncate font-medium">{{ $presenter->label($channel, auth()->user()) }} · {{ $channel->participants_count }} members</span>
                                <button type="button" wire:click="joinChannel({{ $channel->id }})" class="shrink-0 font-semibold text-primary-600">Join</button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            @if($search !== '' && $searchResults->isNotEmpty())
                <div class="border-b border-gray-200 p-2 dark:border-white/10">
                    <p class="px-1 pb-1 text-xs font-semibold text-gray-500">Message results</p>
                    @foreach($searchResults as $result)
                        <button type="button" wire:click="selectConversation({{ $result->conversation_id }})" class="block w-full rounded px-2 py-1.5 text-left hover:bg-gray-50 dark:hover:bg-white/5">
                            <span class="block truncate text-xs font-semibold">{{ $presenter->label($result->conversation, auth()->user()) }} · {{ $result->sender?->name }}</span>
                            <span class="block truncate text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($searchPreviews[$result->id] ?? 'Restricted Record', 90) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            <div class="max-h-[60vh] overflow-y-auto lg:max-h-none lg:flex-1">
                @php($lastSection = null)
                @forelse ($conversations as $item)
                    @if($filter === 'all' && $item->sidebar_section !== $lastSection)
                        @php($lastSection = $item->sidebar_section)
                        <div class="sticky top-0 z-10 bg-gray-50 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide text-gray-500 dark:bg-gray-900">{{ $lastSection }}</div>
                    @endif
                    <button type="button" wire:key="conversation-{{ $item->id }}" wire:click="selectConversation({{ $item->id }})"
                        @class(['chat-conversation-row border-b border-l-2 border-b-gray-100 border-l-transparent px-3 py-3 text-left transition hover:bg-gray-50 dark:border-b-white/5 dark:hover:bg-white/5', 'border-l-primary-600 bg-primary-50 dark:bg-primary-950/30' => $conversation === $item->id])>
                        <div class="flex items-start gap-2.5">
                            <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-gray-600 dark:bg-white/10 dark:text-gray-200" aria-hidden="true">
                                {{ Illuminate\Support\Str::upper(Illuminate\Support\Str::substr($presenter->label($item, auth()->user()), 0, 1)) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-start justify-between gap-3">
                                    <p class="truncate text-sm font-semibold text-gray-950 dark:text-white">{{ $presenter->label($item, auth()->user()) }}</p>
                                    @if ($item->latestMessage?->created_at)<span class="shrink-0 text-[11px] font-normal text-gray-500">{{ $item->latestMessage->created_at->diffForHumans(short: true) }}</span>@endif
                                </div>
                                <div class="mt-1 flex flex-wrap items-center gap-1">
                                    <x-filament::badge size="sm" :color="$presenter->typeColor($item)">{{ $presenter->typeLabel($item) }}</x-filament::badge>
                                    @if ($item->status === \App\Enums\ConversationStatus::Archived)<x-filament::badge size="sm" color="gray">Archived</x-filament::badge>@endif
                                </div>
                                <div class="mt-1.5 flex items-center justify-between gap-2 text-xs text-gray-500">
                                    <span class="truncate">{{ $item->latestMessage ? ($inboxPreviews[$item->latestMessage->id] ?? 'Restricted Record') : 'No messages yet' }}</span>
                                    @if ((int) $item->unread_count > 0)
                                        <span class="min-w-5 shrink-0 rounded-full bg-primary-600 px-1.5 text-center text-xs font-semibold leading-5 text-white" aria-label="{{ $item->unread_count }} unread messages">{{ $item->unread_count > 99 ? '99+' : $item->unread_count }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </button>
                @empty
                    <div class="px-4 py-12 text-center text-sm text-gray-500">No conversations found in this view.</div>
                @endforelse
            </div>
        </aside>

        <section @class(['chat-active-pane min-w-0', 'chat-mobile-hidden' => ! $selected])>
            @if ($selected)
                <div wire:poll.20s="refreshConversation" class="flex h-full min-h-[70vh] min-w-0 flex-col lg:min-h-0">
                    <header class="flex flex-wrap items-center justify-between gap-4 border-b border-gray-200 px-4 py-3 dark:border-white/10 sm:px-5" data-testid="chat-conversation-header">
                        <div class="flex min-w-0 flex-1 items-center gap-3">
                            <button type="button" wire:click="closeConversation" class="rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 dark:hover:bg-white/5 lg:hidden" aria-label="Back to conversations">
                                <x-filament::icon icon="heroicon-o-arrow-left" class="h-5 w-5" />
                            </button>
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary-50 text-sm font-bold text-primary-700 dark:bg-primary-950/40 dark:text-primary-300" aria-hidden="true">{{ $selected->type === \App\Enums\ConversationType::Channel ? '#' : \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($presenter->label($selected, auth()->user()), 0, 1)) }}</span>
                            <div class="min-w-0">
                                <h2 class="truncate text-base font-semibold text-gray-950 dark:text-white">{{ $presenter->label($selected, auth()->user()) }}</h2>
                                <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                                    <x-filament::badge size="sm" :color="$presenter->typeColor($selected)">{{ $presenter->typeLabel($selected) }}</x-filament::badge>
                                    @if ($selected->type === \App\Enums\ConversationType::Channel)
                                        <span>{{ ucfirst($selected->visibility->value) }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ $selected->participants->whereNull('left_at')->count() }} {{ \Illuminate\Support\Str::plural('member', $selected->participants->whereNull('left_at')->count()) }}</span>
                                    @elseif ($selected->type === \App\Enums\ConversationType::Context && $selected->context)
                                        <span class="truncate">{{ $selected->title }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @if ($contextLink)<x-filament::button tag="a" size="sm" color="gray" :href="$contextLink['url']">{{ $contextLink['label'] }}</x-filament::button>@endif
                            @if ($selected->type === \App\Enums\ConversationType::Channel)
                                {{ $this->addChannelMembersAction }}
                                {{ $this->editChannelAction }}
                                {{ $this->removeChannelMemberAction }}
                                <x-filament::button size="sm" color="gray" wire:click="leaveChannel">Leave</x-filament::button>
                            @endif
                            {{ $this->archiveConversationAction }}
                        </div>
                    </header>
                    @if($selected->description)<div class="border-b border-gray-200 px-5 py-2 text-xs text-gray-500 dark:border-white/10">{{ $selected->description }}</div>@endif
                    @if($pinnedMessages->isNotEmpty())
                        <details class="border-b border-gray-200 bg-gray-50 px-5 py-2 text-xs text-gray-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-300">
                            <summary class="cursor-pointer font-semibold">Pinned messages ({{ $pinnedMessages->count() }})</summary>
                            <div class="mt-2 space-y-1.5">@foreach($pinnedMessages as $pin)<button type="button" wire:click="openThread({{ $pin->reply_to_message_id ?: $pin->id }})" class="block w-full truncate rounded-md px-2 py-1 text-left hover:bg-white dark:hover:bg-white/5"><span class="font-medium">{{ $pin->sender?->name }}:</span> {{ \Illuminate\Support\Str::limit($pin->body, 110) }}</button>@endforeach</div>
                        </details>
                    @endif
                    <div class="flex min-h-0 flex-1 flex-col xl:flex-row">
                    <div class="min-w-0 flex-1 space-y-1 overflow-y-auto bg-gray-50/60 px-4 py-4 dark:bg-black/10 sm:px-6">
                        @forelse ($messages as $message)
                            @php($mine = $message->sender_employee_id === auth()->user()->employee->id)
                            <article wire:key="message-{{ $message->id }}" data-message-alignment="{{ $mine ? 'outgoing' : 'incoming' }}" class="group flex gap-3 rounded-lg px-2 py-2.5 text-left hover:bg-white dark:hover:bg-white/5">
                                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-gray-200 text-xs font-bold text-gray-700 dark:bg-white/10 dark:text-gray-200">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($message->sender?->name ?? '?', 0, 1)) }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1" data-testid="chat-message-metadata">
                                        <strong class="text-sm text-gray-950 dark:text-white">{{ $message->sender?->name }}</strong>
                                        <time class="text-[11px] text-gray-500">{{ $message->created_at?->format('d M, h:i A') }}</time>
                                        @if($message->pin)<x-filament::badge size="sm" color="warning" icon="heroicon-m-bookmark">Pinned</x-filament::badge>@endif
                                    </div>
                                    <div class="mt-0.5 whitespace-pre-wrap break-words text-sm leading-6 text-gray-800 dark:text-gray-100">@foreach ($messageSegments[$message->id] ?? [] as $segment)@if ($segment['type'] === 'text'){{ $segment['text'] }}@elseif ($segment['url'])<a href="{{ $segment['url'] }}" class="font-semibold text-primary-600 hover:underline">{{ $segment['label'] }}</a>@else<span class="font-medium text-gray-500">{{ $segment['label'] }}</span>@endif @endforeach</div>
                                    @if ($message->reactions->isNotEmpty())
                                        <div class="mt-2 flex flex-wrap gap-1.5">@foreach($message->reactions->groupBy('reaction') as $reaction => $items)<button type="button" wire:click="toggleReaction({{ $message->id }}, '{{ $reaction }}')" class="inline-flex items-center gap-1 rounded-full border border-gray-200 bg-white px-2 py-0.5 text-xs hover:border-primary-300 dark:border-white/10 dark:bg-white/5" title="{{ $items->pluck('employee.name')->join(', ') }}">{{ ['thumbs_up'=>'👍','heart'=>'❤️','celebrate'=>'🎉','eyes'=>'👀','check'=>'✅'][$reaction] ?? $reaction }} <span>{{ $items->count() }}</span></button>@endforeach</div>
                                    @endif
                                    @if ($selected->status === \App\Enums\ConversationStatus::Active)
                                        <div class="mt-2 flex flex-wrap items-center gap-1.5 text-xs font-medium text-gray-500" data-testid="chat-message-actions">
                                            @if($canReact)<span class="inline-flex items-center overflow-hidden rounded-full border border-gray-200 bg-white dark:border-white/10 dark:bg-white/5">@foreach(['thumbs_up'=>'👍','heart'=>'❤️','celebrate'=>'🎉'] as $key=>$icon)<button type="button" wire:click="toggleReaction({{ $message->id }}, '{{ $key }}')" class="px-2 py-1 hover:bg-gray-100 dark:hover:bg-white/10" title="React {{ $key }}">{{ $icon }}</button>@endforeach</span>@endif
                                            @if($canThread)<button type="button" wire:click="openThread({{ $message->id }})" class="rounded-md px-2 py-1 hover:bg-gray-100 hover:text-primary-600 dark:hover:bg-white/10">{{ $message->replies_count ? $message->replies_count.' replies' : 'Reply in thread' }}</button>@endif
                                            @if($canPin)<button type="button" wire:click="togglePin({{ $message->id }})" class="rounded-md px-2 py-1 hover:bg-gray-100 hover:text-primary-600 dark:hover:bg-white/10">{{ $message->pin ? 'Unpin' : 'Pin' }}</button>@endif
                                        </div>
                                    @endif
                                </div>
                            </article>
                        @empty
                            <div class="flex h-full items-center justify-center py-16 text-center text-sm text-gray-500">No messages yet. Start the conversation below.</div>
                        @endforelse
                    </div>
                    @if($threadRoot)
                        <aside class="w-full max-w-sm shrink-0 border-l border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
                            <div class="flex items-center justify-between border-b p-3 dark:border-white/10"><strong>Thread · {{ $threadMessages->count() }} replies</strong><button type="button" wire:click="closeThread" aria-label="Close thread">✕</button></div>
                            <div class="max-h-[48vh] space-y-3 overflow-y-auto p-3">
                                @foreach(collect([$threadRoot])->concat($threadMessages) as $message)
                                    <article class="text-left"><div class="flex items-baseline gap-2"><strong class="text-sm">{{ $message->sender?->name }}</strong><time class="text-[11px] text-gray-500">{{ $message->created_at?->format('d M, h:i A') }}</time></div><div class="whitespace-pre-wrap break-words text-sm">@foreach ($messageSegments[$message->id] ?? [] as $segment){{ $segment['label'] ?? $segment['text'] }}@endforeach</div></article>
                                @endforeach
                            </div>
                            <form wire:submit="sendThreadReply" class="border-t p-3 dark:border-white/10"><textarea wire:model="threadBody" rows="2" maxlength="5000" class="block w-full rounded-lg border-gray-300 text-sm dark:border-white/10 dark:bg-white/5" placeholder="Reply in thread"></textarea>@error('body')<p class="text-xs text-danger-600">{{ $message }}</p>@enderror<x-filament::button type="submit" size="sm" class="mt-2">Reply</x-filament::button></form>
                        </aside>
                    @endif
                    </div>

                    @if ($selected->status === \App\Enums\ConversationStatus::Active)
                        <form wire:submit="sendMessage" class="sticky bottom-0 w-full space-y-2 border-t border-gray-200 bg-white px-4 py-3 shadow-[0_-4px_12px_rgba(0,0,0,.04)] dark:border-white/10 dark:bg-gray-900 sm:px-6" data-testid="chat-composer">
                            @if ($replyMessage)
                                <div class="flex items-center justify-between rounded-lg bg-gray-100 px-3 py-2 text-xs dark:bg-white/5">
                                    <span class="truncate">Replying to {{ $replyMessage->sender?->name }}: “{{ \Illuminate\Support\Str::limit($replyMessage->body, 100) }}”</span>
                                    <button type="button" wire:click="clearReply" class="ml-2 font-semibold text-danger-600">Cancel</button>
                                </div>
                            @endif
                            @if ($selectedMentionNames)
                                <div class="flex flex-wrap items-center gap-1 text-xs text-gray-500"><span>Mentioning:</span>@foreach ($selectedMentionNames as $name)<x-filament::badge size="sm" color="info">{{ $name }}</x-filament::badge>@endforeach</div>
                            @endif
                            <div class="w-full">
                                <textarea wire:model="body" rows="3" maxlength="5000" class="block w-full resize-y rounded-xl border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5" placeholder="Type a message…" aria-label="Message"></textarea>
                                @error('body')<p class="mt-1 text-xs text-danger-600">{{ $message }}</p>@enderror
                                @error('mention_employee_ids')<p class="mt-1 text-xs text-danger-600">{{ $message }}</p>@enderror
                            </div>
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex flex-wrap items-center gap-2">
                                    {{ $this->mentionEmployeesAction }}
                                    {{ $this->mentionRecordAction }}
                                </div>
                                <x-filament::button type="submit" icon="heroicon-o-paper-airplane">Send</x-filament::button>
                            </div>
                        </form>
                    @else
                        <div class="border-t border-gray-200 p-3 text-center text-sm text-gray-500 dark:border-white/10">This conversation is archived. Message history remains available.</div>
                    @endif
                </div>
            @else
                <div class="flex min-h-[68vh] items-center justify-center text-center"><div><x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="mx-auto h-10 w-10 text-gray-400" /><p class="mt-3 text-sm font-medium text-gray-700 dark:text-gray-200">Select a conversation to start chatting.</p></div></div>
            @endif
        </section>
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
