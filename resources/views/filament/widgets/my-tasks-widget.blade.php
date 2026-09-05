<x-filament-widgets::widget>
    <x-filament::section compact>
        <x-slot name="heading">My Tasks</x-slot>
        <x-slot name="afterHeader">
            <x-filament::link :href="$viewAllUrl">View All Tasks</x-filament::link>
        </x-slot>

        <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-5">
            @foreach (['pending' => 'Pending / Assigned', 'in_progress' => 'In Progress', 'waiting' => 'Waiting', 'awaiting_confirmation' => 'Awaiting Confirmation', 'overdue' => 'Overdue'] as $key => $label)
                <div class="rounded-lg border border-gray-200 px-3 py-2 dark:border-white/10" data-summary-key="{{ $key }}" data-summary-count="{{ $summary[$key] }}">
                    <div class="text-xl font-semibold leading-none">{{ number_format($summary[$key]) }}</div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $label }}</div>
                </div>
            @endforeach
        </div>

        @if ($rows->isEmpty())
            <div class="py-5 text-center text-sm text-gray-500 dark:text-gray-400">
                You have no pending tasks right now.
            </div>
        @else
            <div class="mt-3 overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                <table class="w-full min-w-[820px] divide-y divide-gray-200 text-sm dark:divide-white/10">
                    <thead class="bg-gray-50 dark:bg-white/5">
                        <tr>
                            @foreach (['Task Ref', 'Title', 'Status', 'Priority', 'Due', 'Related Record', ''] as $heading)
                                <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-300">{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                        @foreach ($rows as $row)
                            @php($task = $row['task'])
                            <tr wire:key="dashboard-task-{{ $task->id }}" data-task-reference="{{ $task->reference }}" data-task-status="{{ $row['status'] }}" class="hover:bg-gray-50/70 dark:hover:bg-white/[0.03]">
                                <td class="whitespace-nowrap px-3 py-2">
                                    <a class="font-semibold text-primary-600 hover:underline dark:text-primary-400" href="{{ $row['url'] }}">{{ $task->reference }}</a>
                                </td>
                                <td class="max-w-64 px-3 py-2">
                                    <div class="truncate font-medium" title="{{ $task->title }}">{{ $task->title }}</div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-2"><x-filament::badge :color="$row['status_color']">{{ $row['status_label'] }}</x-filament::badge></td>
                                <td class="whitespace-nowrap px-3 py-2"><x-filament::badge :color="$task->priority->getColor()">{{ $task->priority->getLabel() }}</x-filament::badge></td>
                                <td class="whitespace-nowrap px-3 py-2 text-xs {{ $row['overdue'] ? 'font-semibold text-danger-600 dark:text-danger-400' : 'text-gray-600 dark:text-gray-300' }}">{{ $row['due'] }}</td>
                                <td class="px-3 py-2">
                                    @if ($row['linked']['url'])
                                        <a class="text-primary-600 hover:underline dark:text-primary-400" href="{{ $row['linked']['url'] }}">{{ $row['linked']['label'] }}</a>
                                    @else
                                        {{ $row['linked']['label'] }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right"><x-filament::button tag="a" size="xs" :href="$row['url']">Open Task</x-filament::button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
