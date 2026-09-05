<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CustomerReturnStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case QcPending = 'qc_pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft', self::QcPending => 'QC Pending',
            self::Completed => 'Completed', self::Cancelled => 'Cancelled',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::QcPending => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'danger',
        };
    }
}
