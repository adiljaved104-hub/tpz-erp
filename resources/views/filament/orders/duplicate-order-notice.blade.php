@if($notice)
<div role="alert" data-testid="duplicate-order-notice" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-950 dark:border-red-800 dark:bg-red-950 dark:text-red-100">
    <strong class="block text-red-700 dark:text-red-200">Duplicate Order Detected</strong>
    <p>{{ $notice['message'] }}</p>
    @if($notice['order'] && \App\Filament\Resources\Orders\OrderResource::canView($notice['order']))
        <a class="font-semibold underline" href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('view', ['record'=>$notice['order']]) }}">View matching order {{ $notice['order']->reference }}</a>
    @endif
    <small>Use the existing order or correct the marketplace reference. Duplicate submissions are rejected when saving.</small>
</div>
@endif
