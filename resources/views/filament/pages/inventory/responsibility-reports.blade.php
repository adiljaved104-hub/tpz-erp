<x-filament-panels::page>
    <div class="grid min-w-0 gap-4 xl:grid-cols-2">
        @foreach ([
            'by_employee' => 'Responsibility by Employee',
            'by_brand' => 'Responsibility by Brand',
            'by_category' => 'Responsibility by Category',
            'by_platform' => 'Responsibility by Platform',
            'by_product' => 'Responsibility by Product',
            'over_assigned' => 'Over-assigned Products',
            'unassigned_products' => 'Unassigned Products',
            'history' => 'Assignment History',
        ] as $key => $title)
            <x-filament::section :heading="$title" compact>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
                        <tbody class="divide-y divide-gray-100 dark:divide-white/5">
                            @forelse ($reports[$key] as $row)
                                <tr>
                                    @foreach ((array) $row as $field => $value)
                                        <td class="max-w-72 px-3 py-2 align-top"><span class="text-xs font-medium text-gray-500">{{ str($field)->headline() }}</span><br><span class="line-clamp-2" title="{{ $value }}">{{ $value }}</span></td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr><td class="px-3 py-6 text-center text-gray-500">No records.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
