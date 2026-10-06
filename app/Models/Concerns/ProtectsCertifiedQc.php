<?php

namespace App\Models\Concerns;

use App\Enums\QcInspectionStatus;
use LogicException;

trait ProtectsCertifiedQc
{
    protected static function bootProtectsCertifiedQc(): void
    {
        foreach (['creating', 'updating', 'deleting'] as $event) {
            static::{$event}(function ($record): void {
                if ($record->inspection()->where('status', QcInspectionStatus::Completed->value)->exists()) {
                    throw new LogicException('Evidence and checks for completed QC are immutable.');
                }
            });
        }
    }
}
