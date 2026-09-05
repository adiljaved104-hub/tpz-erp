<?php

namespace App\Models;

use App\Enums\EmployeeRole;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'employee_id',
        'name',
        'email',
        'phone',
        'team_id',
        'designation',
        'role',
        'status',
        'joining_date',
    ];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'role' => EmployeeRole::class,
            'status' => 'boolean',
            'joining_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function handledPurchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'handled_by_employee_id');
    }

    public function handledOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'handled_by_employee_id');
    }

    public function responsibilityAssignments(): HasMany
    {
        return $this->hasMany(ResponsibilityAssignment::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(EmployeePermissionOverride::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_employee_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(EmployeeAttendance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function compensatoryOffs(): HasMany
    {
        return $this->hasMany(CompensatoryOff::class);
    }

    public function workScheduleAssignments(): HasMany
    {
        return $this->hasMany(WorkScheduleAssignment::class);
    }

    public function biometricIdentifiers(): HasMany
    {
        return $this->hasMany(BiometricEmployeeIdentifier::class);
    }

    public function conversationParticipations(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    public function sentConversationMessages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'sender_employee_id');
    }
}
