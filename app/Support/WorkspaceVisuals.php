<?php

namespace App\Support;

/**
 * Presentation-only icon and tone mapping for the New ERP reference design.
 * Counts and records still originate from the authorized read services.
 */
final class WorkspaceVisuals
{
    public static function alertIcon(string $label): string
    {
        $name = strtolower($label);

        return match (true) {
            str_contains($name, 'task') => 'heroicon-o-clipboard-document-list',
            str_contains($name, 'claim') => 'heroicon-o-shield-check',
            str_contains($name, 'qc'), str_contains($name, 'inspect') => 'heroicon-o-magnifying-glass',
            str_contains($name, 'damaged') => 'heroicon-o-exclamation-triangle',
            str_contains($name, 'return') => 'heroicon-o-arrow-uturn-left',
            str_contains($name, 'purchas'), str_contains($name, 'deliver') => 'heroicon-o-truck',
            str_contains($name, 'repair'), str_contains($name, 'warranty') => 'heroicon-o-wrench-screwdriver',
            str_contains($name, 'complaint') => 'heroicon-o-megaphone',
            str_contains($name, 'payment'), str_contains($name, 'invoice') => 'heroicon-o-receipt-percent',
            str_contains($name, 'leave'), str_contains($name, 'attend') => 'heroicon-o-calendar-days',
            default => 'heroicon-o-bell-alert',
        };
    }

    public static function alertTone(string $label): string
    {
        $name = strtolower($label);

        return match (true) {
            str_contains($name, 'damage'), str_contains($name, 'complaint'),
                str_contains($name, 'overdue') => 'danger',
            str_contains($name, 'claim'), str_contains($name, 'return'),
                str_contains($name, 'repair'), str_contains($name, 'qc') => 'warning',
            default => 'info',
        };
    }

    public static function movementIcon(string $label): string
    {
        $name = strtolower($label);

        return match (true) {
            str_contains($name, 'receipt'), str_contains($name, 'received') => 'heroicon-o-cube',
            str_contains($name, 'adjust') => 'heroicon-o-adjustments-horizontal',
            str_contains($name, 'damage'), str_contains($name, 'fail') => 'heroicon-o-exclamation-triangle',
            str_contains($name, 'qc'), str_contains($name, 'inspect') => 'heroicon-o-magnifying-glass',
            str_contains($name, 'reserve') => 'heroicon-o-archive-box',
            default => 'heroicon-o-arrows-right-left',
        };
    }

    public static function movementTone(string $label): string
    {
        $name = strtolower($label);

        return match (true) {
            str_contains($name, 'receipt'), str_contains($name, 'received') => 'success',
            str_contains($name, 'damage'), str_contains($name, 'fail') => 'danger',
            str_contains($name, 'qc'), str_contains($name, 'inspect') => 'warning',
            str_contains($name, 'adjust') => 'info',
            default => 'violet',
        };
    }
}
