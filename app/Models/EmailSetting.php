<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailSetting extends Model
{
    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'enabled', 'smtp_host', 'smtp_port', 'encryption', 'smtp_username',
        'smtp_password_encrypted', 'from_email', 'from_name', 'last_successful_test_at',
        'last_failed_test_at', 'updated_by_user_id',
    ];

    protected $hidden = ['smtp_password_encrypted'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'smtp_port' => 'integer',
            'smtp_password_encrypted' => 'encrypted',
            'last_successful_test_at' => 'immutable_datetime',
            'last_failed_test_at' => 'immutable_datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
