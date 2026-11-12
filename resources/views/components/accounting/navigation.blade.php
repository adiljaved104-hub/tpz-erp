@props(['links' => []])

<nav aria-label="Accounting work areas" class="flex flex-wrap gap-2">
    @foreach ($links as $link)
        <x-filament::button :href="$link['url']" tag="a" :color="$link['active'] ? 'primary' : 'gray'" size="sm" :icon="$link['icon']">{{ $link['label'] }}</x-filament::button>
    @endforeach
</nav>
