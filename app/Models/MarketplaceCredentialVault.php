<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceCredentialVault extends Model
{
    protected $fillable = ['reference', 'encrypted_payload'];

    protected $hidden = ['encrypted_payload'];

    protected function casts(): array
    {
        return ['encrypted_payload' => 'encrypted:array'];
    }
}
