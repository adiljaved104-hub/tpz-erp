<?php

namespace App\Services\Search;

use App\Contracts\GlobalSearchProvider;
use App\DTOs\GlobalSearchResult;
use App\Enums\HrPermission;
use App\Filament\Resources\EmployeeWarnings\EmployeeWarningResource;
use App\Filament\Resources\HrNotices\HrNoticeResource;
use App\Models\EmployeeWarning;
use App\Models\HrNotice;
use App\Models\User;
use App\Services\Authorization\HrRecordAuthorization;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HrSearchProvider implements GlobalSearchProvider
{
    public function search(User $user, string $query, int $limit): Collection
    {
        $authorization = app(HrRecordAuthorization::class);
        $results = collect();

        if ($authorization->allows($user, HrPermission::NoticeView)
            || $authorization->allows($user, HrPermission::NoticeManage)
            || $authorization->allows($user, HrPermission::NoticePublish)) {
            $ids = $authorization->scopeNotices(HrNotice::query()->select('hr_notices.id'), $user);
            $notices = DB::table('hr_notices')->whereIn('id', $ids)->select(['id', 'reference', 'title', 'priority', 'status']);
            SearchQuery::match($notices, ['reference', 'title'], $query);
            SearchQuery::rank($notices, 'reference', $query);
            SearchQuery::rank($notices, 'title', $query);
            $results = $results->concat($notices->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult(
                'Notices', $row->reference.' · '.$row->title, ucfirst($row->priority).' · '.ucfirst($row->status),
                HrNoticeResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-megaphone',
            )));
        }

        if ($authorization->allows($user, HrPermission::WarningViewOwn)
            || $authorization->allows($user, HrPermission::WarningViewTeam)
            || $authorization->allows($user, HrPermission::WarningViewAll)) {
            $ids = $authorization->scopeWarnings(EmployeeWarning::query()->select('employee_warnings.id'), $user);
            $warnings = DB::table('employee_warnings')->join('employees', 'employees.id', '=', 'employee_warnings.employee_id')
                ->whereIn('employee_warnings.id', $ids)->select(['employee_warnings.id', 'employee_warnings.reference', 'employee_warnings.title', 'employee_warnings.warning_level', 'employee_warnings.status', 'employees.name as employee']);
            SearchQuery::match($warnings, ['employee_warnings.reference', 'employee_warnings.title', 'employees.name'], $query);
            SearchQuery::rank($warnings, 'employee_warnings.reference', $query);
            SearchQuery::rank($warnings, 'employee_warnings.title', $query);
            $results = $results->concat($warnings->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult(
                'Warnings', $row->reference.' · '.$row->title, $row->employee.' · '.ucfirst($row->warning_level).' · '.ucfirst($row->status),
                EmployeeWarningResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-exclamation-triangle',
            )));
        }

        return $results;
    }
}
