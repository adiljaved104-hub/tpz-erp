<x-filament::section>
    <div class="mb-3 flex flex-wrap justify-between gap-3">
        <x-filament::input.wrapper><x-filament::input type="search" placeholder="Search product or SKU" wire:model.live.debounce.300ms="productSearch" /></x-filament::input.wrapper>
        @if(\App\Filament\Resources\Products\ProductResource::canCreate())
            <x-filament::button wire:click="mountAction('createPurchasingProduct')">Add Product</x-filament::button>
        @endif
    </div>
    <div class="overflow-x-auto"><table class="tpz-workspace-table">
        <thead><tr><th>SKU</th><th>Product</th><th>Action</th></tr></thead>
        <tbody>@forelse($purchasingProducts as $product)<tr><td>{{ $product->sku }}</td><td class="whitespace-normal">{{ $product->name }}</td><td>@if(\App\Filament\Resources\Products\ProductResource::canView($product))<x-filament::button size="xs" tag="a" :href="\App\Filament\Resources\Products\ProductResource::getUrl('view', ['record' => $product])">View</x-filament::button>@endif</td></tr>@empty<tr><td colspan="3">No products in your authorized scope.</td></tr>@endforelse</tbody>
    </table></div>
    {{ $purchasingProducts->links() }}
</x-filament::section>
