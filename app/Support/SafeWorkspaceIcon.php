<?php

namespace App\Support;

use Throwable;

/**
 * A missing third-party navigation icon must not take down an entire
 * authenticated New ERP workspace.
 *
 * The UI remains presentation-only. No navigation permissions are changed.
 */
final class SafeWorkspaceIcon
{
    /** @var array<string, string> */
    private static array $resolved = [];

    public static function resolve(mixed $icon): string
    {
        $fallback = 'heroicon-o-squares-2x2';

        if ($icon instanceof \BackedEnum) {
            $icon = $icon->value;
        }

        if (! is_string($icon) || $icon === '') {
            return $fallback;
        }

        // Filament Heroicon enum backing values are often "o-..." or
        // "s-..." rather than a blade-icons set-qualified name.
        // Expand only those known prefixes before validating the SVG.
        if (preg_match('/^[osm]-[a-z0-9-]+$/', $icon) === 1) {
            $icon = 'heroicon-'.$icon;
        }

        if (isset(self::$resolved[$icon])) {
            return self::$resolved[$icon];
        }

        // Some navigation icons are registered in Filament but their SVG
        // assets are not installed. Validate before the Blade component
        // attempts to render them (which otherwise throws HTTP 500).
        try {
            svg($icon);

            return self::$resolved[$icon] = $icon;
        } catch (Throwable) {
            return self::$resolved[$icon] = $fallback;
        }
    }
}
