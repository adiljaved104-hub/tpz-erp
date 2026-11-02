<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerReturnVideoUploadSession extends Model
{
    protected $guarded = [];

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'finding' => 'array', 'capture' => 'array', 'chunks' => 'array',
            'byte_size' => 'integer', 'duration_seconds' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
