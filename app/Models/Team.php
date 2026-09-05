<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $fillable = [
        'name',
        'description',
        'status',
    ];

    public function responsibilityAssignments(): HasMany
    {
        return $this->hasMany(ResponsibilityAssignment::class, 'team_id_at_assignment');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_team_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
