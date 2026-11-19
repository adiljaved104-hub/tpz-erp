<?php

namespace App\Support;

/**
 * Presentation-only icon and tone mapping for the New ERP reference design.
 * Counts and records still originate from the authorized read services.
 */
final class WorkspaceVisuals
{

    /**
     * Replace generic external-link arrows in workspace tabs with recognizable
     * module icons. This is presentation-only; URL and access remain unchanged.
     */
    public static function moduleIcon(string $label, mixed $icon): string
    {
        if ($icon instanceof \\BackedEnum) {
            $icon = $icon->value;
        }

        if (is_string($icon) && $icon !== ''
            && ! in_array($icon, ['heroicon-o-arrow-up-right', 'o-arrow-up-right', 'heroicon-o-arrow-top-right-on-square', 'o-arrow-top-right-on-square'], true)) {
            return $icon;
        }

        $name = strtolower($label);

        return match (true) {
            str_contains($name, 'quickbooks'), str_contains($name, 'import') => 'heroicon-o-arrow-down-tray',
            str_contains($name, 'damaged'), str_contains($name, 'rejected') => 'heroicon-o-exclamation-triangle',
            str_contains($name, 'receiving'), str_contains($name, 'received') => 'heroicon-o-inbox-arrow-down',
            str_contains($name, 'delivery'), str_contains($name, 'deliveries') => 'heroicon-o-truck',
            str_contains($name, 'purchas'), str_contains($name, 'supplier') => 'heroicon-o-shopping-cart',
            str_contains($name, 'invoice') => 'heroicon-o-document-text',
            str_contains($name, 'cost'), str_contains($name, 'outstanding') => 'heroicon-o-banknotes',
            str_contains($name, 'stock'), str_contains($name, 'inventory') => 'heroicon-o-cube',
            str_contains($name, 'reservation') => 'heroicon-o-bookmark',
            str_contains($name, 'report') => 'heroicon-o-chart-bar',
            default => 'heroicon-o-document-text',
        };
    }

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

    public static function recordTone(string $status): string
    {
        $value = strtolower($status);

        return match (true) {
            str_contains($value, 'fulfill'), str_contains($value, 'complet'),
                str_contains($value, 'receiv'), str_contains($value, 'approved'),
                str_contains($value, 'posted') => 'success',
            str_contains($value, 'cancel'), str_contains($value, 'reject'),
                str_contains($value, 'fail'), str_contains($value, 'damage') => 'danger',
            str_contains($value, 'pending'), str_contains($value, 'review'),
                str_contains($value, 'await'), str_contains($value, 'hold') => 'warning',
            str_contains($value, 'draft') => 'neutral',
            str_contains($value, 'reserve'), str_contains($value, 'process') => 'info',
            default => 'violet',
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
