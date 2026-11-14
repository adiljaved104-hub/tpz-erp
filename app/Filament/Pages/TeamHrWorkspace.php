<?php

namespace App\Filament\Pages;

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
                    ['label' => 'Employees', 'class' => \App\Filament\Resources\Employees\EmployeeResource::class, 'icon' => 'heroicon-o-users'],
                    ['label' => 'Teams', 'class' => \App\Filament\Resources\Teams\TeamResource::class, 'icon' => 'heroicon-o-user-group'],
                ],
            ],
            [
                'title' => 'HR operations',
                'description' => 'Existing attendance, leave and work schedule processes',
                'links' => [
                    ['label' => 'Attendance', 'class' => \App\Filament\Pages\Hr\Attendance::class, 'icon' => 'heroicon-o-clock'],
                    ['label' => 'Leave Management', 'class' => \App\Filament\Pages\Hr\LeaveManagement::class, 'icon' => 'heroicon-o-calendar-days'],
                    ['label' => 'Comp-Off', 'class' => \App\Filament\Pages\Hr\CompOff::class, 'icon' => 'heroicon-o-calendar'],
                    ['label' => 'Work Schedules', 'class' => \App\Filament\Pages\Hr\WorkSchedules::class, 'icon' => 'heroicon-o-clipboard-document-list'],
                    ['label' => 'HR Settings', 'class' => \App\Filament\Pages\Hr\HrSettings::class, 'icon' => 'heroicon-o-cog-6-tooth'],
                ],
            ],
            ],
        ];
    }
}
