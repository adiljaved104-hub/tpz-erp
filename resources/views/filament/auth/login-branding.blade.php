@php($branding = app(\App\Services\LoginBrandingService::class)->presentation())

<x-auth.branding :branding="$branding" />
