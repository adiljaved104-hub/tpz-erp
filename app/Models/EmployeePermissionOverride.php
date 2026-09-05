<?php

namespace App\Models;

use App\Enums\EmployeePermissionEffect;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeePermissionOverride extends Model
{
    protected $fillable = [
        'employee_id',
        'permission_key',
        'effect',
        'granted_by_user_id',
        'reason',
    ];

    protected function casts(): array
    {
        return ['effect' => EmployeePermissionEffect::class];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by_user_id');
    }
}
