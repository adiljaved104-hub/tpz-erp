<x-filament-panels::page>
    @if ($canViewTeam || $canViewUnassigned)
        <div class="inline-flex flex-wrap gap-1 rounded-xl bg-gray-100 p-1 dark:bg-white/5" role="tablist" aria-label="Task work view">
            <x-filament::button size="xs" role="tab" :aria-selected="$viewMode === 'mine'" :color="$viewMode === 'mine' ? 'primary' : 'gray'" wire:click="setViewMode('mine')">My Work</x-filament::button>
            @if ($canViewTeam)<x-filament::button size="xs" role="tab" :aria-selected="$viewMode === 'team'" :color="$viewMode === 'team' ? 'primary' : 'gray'" wire:click="setViewMode('team')">Team Work</x-filament::button>@endif
            @if ($canViewUnassigned)<x-filament::button size="xs" role="tab" :aria-selected="$viewMode === 'unassigned'" :color="$viewMode === 'unassigned' ? 'primary' : 'gray'" wire:click="setViewMode('unassigned')">Unassigned</x-filament::button>@endif
        </div>
    @endif

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        @foreach (['pending' => 'Pending', 'in_progress' => 'In Progress', 'waiting' => 'Waiting', 'awaiting_confirmation' => 'Awaiting Confirmation', 'overdue' => 'Overdue'] as $key => $label)
            <x-filament::section compact><div class="text-2xl font-semibold leading-none">{{ number_format($summary[$key]) }}</div><div class="mt-1 text-xs text-gray-500">{{ $label }}</div></x-filament::section>
        @endforeach
    </div>

    <x-filament::section compact>
        <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label><span class="mb-1 block text-xs font-medium">Search Tasks</span><x-filament::input.wrapper><x-filament::input type="search" wire:model.live.debounce.300ms="search" placeholder="Task reference or title" /></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-xs font-medium">Priority</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="priority"><option value="">All priorities</option>@foreach (\App\Enums\TaskPriority::cases() as $option)<option value="{{ $option->value }}">{{ $option->getLabel() }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <label><span class="mb-1 block text-xs font-medium">Status</span><x-filament::input.wrapper><x-filament::input.select wire:model.live="status"><option value="">All active statuses</option>@foreach ([\App\Enums\TaskStatus::Pending, \App\Enums\TaskStatus::Assigned, \App\Enums\TaskStatus::InProgress, \App\Enums\TaskStatus::Waiting] as $option)<option value="{{ $option->value }}">{{ $option->getLabel() }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
            <x-filament::button size="sm" color="gray" wire:click="resetFilters">Clear Filters</x-filament::button>
        </div>
    </x-filament::section>

    @if ($rows->isEmpty())
        <x-filament::section><div class="py-8 text-center text-sm text-gray-500">You have no pending work right now.</div></x-filament::section>
    @else
        <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
            <table class="w-full min-w-[1050px] divide-y divide-gray-200 text-sm dark:divide-white/10">
                <thead class="bg-gray-50 dark:bg-white/5"><tr>@foreach (['Task Ref','Title','Priority','Status','Due / Time Left','Assigned To','Team','Linked Record','Next Action'] as $heading)<th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ $heading }}</th>@endforeach</tr></thead>
                <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($rows as $task)
                        <tr wire:key="task-work-{{ $task->id }}" class="hover:bg-gray-50/70 dark:hover:bg-white/[0.03]">
                            <td class="px-3 py-2"><a class="whitespace-nowrap font-semibold text-primary-600 hover:underline" href="{{ $taskUrl($task) }}">{{ $task->reference }}</a></td>
                            <td class="max-w-72 px-3 py-2"><div class="truncate font-medium" title="{{ $task->title }}">{{ $task->title }}</div></td>
                            <td class="px-3 py-2"><x-filament::badge :color="$task->priority->getColor()">{{ $task->priority->getLabel() }}</x-filament::badge></td>
                            <td class="px-3 py-2"><x-filament::badge :color="$task->hasPendingCompletion() ? 'warning' : $task->status->getColor()">{{ $task->hasPendingCompletion() ? 'Awaiting Confirmation' : $task->status->getLabel() }}</x-filament::badge></td>
                            <td class="whitespace-nowrap px-3 py-2 text-xs {{ $task->isOverdue() ? 'font-semibold text-danger-600' : '' }}">{{ $task->dueLabel() ?? 'No due date' }}</td>
                            <td class="min-w-44 px-3 py-2">
                                @php($activeAssignments = $task->assignments->whereNotIn('status.value', ['removed', 'cancelled']))
                                @if (auth()->user()->can('task.assign', $task) && $activeAssignments->count() <= 1 && $task->assigned_team_id === null)
                                    <x-filament::input.wrapper><x-filament::input.select wire:change="assignTask({{ $task->id }}, $event.target.value)" aria-label="Assign {{ $task->reference }}"><option value="">Unassigned</option>@foreach ($assigneeOptions[$task->id] as $id => $label)<option value="{{ $id }}" @selected($activeAssignments->first()?->employee_id === $id)>{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                                @else {{ $task->assignmentLabel() }} @endif
                            </td>
                            <td class="px-3 py-2">{{ $task->assigned_team_id ? $task->assignedTeam?->name : ($activeAssignments->pluck('team_name_at_assignment')->filter()->unique()->join(', ') ?: '—') }}</td>
                            <td class="px-3 py-2">@if ($contexts[$task->id]['url'])<a class="text-primary-600 hover:underline" href="{{ $contexts[$task->id]['url'] }}">{{ $contexts[$task->id]['label'] }}</a>@else{{ $contexts[$task->id]['label'] }}@endif</td>
                            <td class="sticky right-0 bg-white px-3 py-2 dark:bg-gray-900"><x-filament::button tag="a" size="sm" :href="$taskUrl($task)" class="whitespace-nowrap">{{ $service->nextAction($task) }}</x-filament::button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    @endif
</x-filament-panels::page>
