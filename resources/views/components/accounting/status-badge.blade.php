@props(['status', 'tone' => null])

@php
    $tone ??= match (strtolower((string) $status)) {
        'active', 'open', 'posted' => 'success',
        'draft' => 'warning',
        'inactive', 'closed', 'locked', 'reversed' => 'gray',
        default => 'gray',
    };
@endphp

<x-filament::badge :color="$tone" size="sm">{{ $status }}</x-filament::badge>
