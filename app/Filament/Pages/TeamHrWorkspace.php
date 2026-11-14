<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Hr\Attendance;
use App\Filament\Pages\Hr\CompOff;
use App\Filament\Pages\Hr\HrSettings;
use App\Filament\Pages\Hr\LeaveManagement;
use App\Filament\Pages\Hr\WorkSchedules;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Teams\TeamResource;
use Filament\Support\Icons\Heroicon;

class TeamHrWorkspace extends BaseOperationalWorkspace
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'HR';

    protected static ?string $navigationLabel = 'Team & HR Workspace';

    protected static ?string $slug = 'team-hr-workspace';

    protected static function workspaceDefinition(): array
    {
        return [
            'title' => 'Team & HR Workspace',
            'eyebrow' => 'People & workplace operations',
            'description' => 'Employees, leave, attendance and schedules using the existing HR approval rules.',
            'icon' => 'heroicon-o-squares-2x2',
            'sections' => [
                [
                    'title' => 'People',
                    'description' => 'Employee and team records',
                    'links' => [
                        ['label' => 'Employees', 'class' => EmployeeResource::class, 'icon' => 'heroicon-o-users'],
                        ['label' => 'Teams', 'class' => TeamResource::class, 'icon' => 'heroicon-o-user-group'],
                    ],
                ],
                [
                    'title' => 'HR operations',
                    'description' => 'Existing attendance, leave and work schedule processes',
                    'links' => [
                        ['label' => 'Attendance', 'class' => Attendance::class, 'icon' => 'heroicon-o-clock'],
                        ['label' => 'Leave Management', 'class' => LeaveManagement::class, 'icon' => 'heroicon-o-calendar-days'],
                        ['label' => 'Comp-Off', 'class' => CompOff::class, 'icon' => 'heroicon-o-calendar'],
                        ['label' => 'Work Schedules', 'class' => WorkSchedules::class, 'icon' => 'heroicon-o-clipboard-document-list'],
                        ['label' => 'HR Settings', 'class' => HrSettings::class, 'icon' => 'heroicon-o-cog-6-tooth'],
                    ],
                ],
            ],
        ];
    }
}
