<x-dynamic-component :component="$embedded ? 'workspace-inline-panel' : 'filament-panels::page'">
    {{ $this->content }}
    @if($embedded)<x-filament-actions::modals />@endif
</x-dynamic-component>
