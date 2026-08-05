<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;



class Employee extends Model
{
    protected $fillable = [
        'employee_id',
        'name',
        'email',
        'password',
        'phone',
        'team_id',
        'designation',
        'role',
        'status',
        'joining_date',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    protected function password(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => bcrypt($value),
        );
    }

    protected static function booted(): void
    {
        static::creating(function ($employee) {

            $last = self::latest()->first();

            $number = $last
                ? intval(substr($last->employee_id, 4)) + 1
                : 1;

            $employee->employee_id = 'TPZ-' . str_pad($number, 4, '0', STR_PAD_LEFT);

        });
    }
}