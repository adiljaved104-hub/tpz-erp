@if($notice)
    <div role="alert" aria-live="polite" data-testid="duplicate-order-notice"
         class="rounded-xl border border-red-300 bg-red-50 p-4 text-sm text-red-950 shadow-sm dark:border-red-800 dark:bg-red-950/60 dark:text-red-100">
        <div class="flex items-start gap-3">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-full border border-red-200 bg-white/80 text-red-600 dark:border-red-700 dark:bg-red-950 dark:text-red-300" aria-hidden="true">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="size-6"/>
            </span>
            <div class="min-w-0 flex-1">
                <strong class="block text-base font-bold text-red-700 dark:text-red-200">Duplicate Order Detected</strong>
                <p class="mt-1 leading-relaxed">{{ $notice['message'] }}</p>

                @if($notice['order'] && \App\Filament\Resources\Orders\OrderResource::canView($notice['order']))
                    <div class="mt-3 rounded-lg border border-red-200 bg-white/70 px-3 py-2 dark:border-red-800 dark:bg-red-950/50">
                        <span class="block text-xs font-medium text-red-700 dark:text-red-300">Existing Sales Order</span>
                        <a class="mt-1 inline-flex items-center gap-1 break-all font-bold text-red-800 underline underline-offset-2 hover:text-red-600 dark:text-red-100"
                           href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record'=>$notice['order']]) }}">
                            {{ $notice['order']->reference }} <span aria-hidden="true">↗</span>
                        </a>
                    </div>
                @endif

                <p class="mt-3 text-xs leading-relaxed text-red-800 dark:text-red-200">
                    Use the existing order or correct the marketplace order number. This duplicate will be rejected when saving.
                </p>
            </div>
        </div>
    </div>
@endif
