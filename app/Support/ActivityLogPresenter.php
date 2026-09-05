<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Support\Str;

final class ActivityLogPresenter
{
    public static function event(?string $event): string
    {
        return filled($event) ? Str::headline(str_replace('.', ' ', $event)) : 'Activity';
    }

    public static function subject(?string $type): string
    {
        return filled($type) ? Str::headline(class_basename($type)) : 'System';
    }

    public static function description(ActivityLog $log): string
    {
        return filled($log->description) ? $log->description : self::event($log->event);
    }
}
