<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Responsibilities\DeactivateResponsibilityAssignment;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\Enums\ResponsibilityPermission;
use App\Models\ResponsibilityAssignment;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResponsibilityController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $auth = app(ResponsibilityAuthorization::class);
        $u = $request->user();
        abort_unless($auth->allows($u, ResponsibilityPermission::ViewAll) || $auth->allows($u, ResponsibilityPermission::ViewTeam) || $auth->allows($u, ResponsibilityPermission::ViewOwn), 403);

        return $this->page($request, app(ResponsibilityReadService::class)->assignmentsFor($u)->orderByDesc('id'), ['reference'], fn ($r) => $this->present($request, $r));
    }

    public function show(Request $request, ResponsibilityAssignment $responsibility): JsonResponse
    {
        abort_unless(app(ResponsibilityAuthorization::class)->canView($request->user(), $responsibility), 403);

        return response()->json(['data' => $this->present($request, $responsibility, true)]);
    }

    private function present(Request $request, ResponsibilityAssignment $r, bool $detail = false): array
    {
        $scope = collect([$r->brandScope?->brand?->name, $r->categoryScope?->category?->name, $r->productScope?->product?->name,
            $r->quantityScope?->inventory?->product?->name, $r->platformScope?->platform?->name])->filter()->implode(' · ');
        $data = ['id' => $r->id, 'title' => $r->reference, 'subtitle' => $scope, 'meta' => $r->employee?->name, 'status' => $r->status->value];
        if (! $detail) {
            return $data;
        }
        $actions = [];
        if ($r->status->value === 'active' && app(ResponsibilityAuthorization::class)->allows($request->user(), ResponsibilityPermission::Deactivate, $r)) {
            $actions[] = $this->action('deactivate', 'End responsibility', [$this->field('reason', 'Reason', 'multiline', true)]);
        }

        return [...$data, 'fields' => ['scope' => $scope, 'employee' => $r->employee?->name, 'assigned_quantity' => $r->quantityScope?->assigned_quantity,
            'platform' => $r->platformScope?->platform?->name], 'actions' => $actions];
    }

    public function act(Request $request, ResponsibilityAssignment $responsibility, string $action): JsonResponse
    {
        abort_unless($action === 'deactivate', 404);
        abort_unless(app(ResponsibilityAuthorization::class)->canView($request->user(), $responsibility), 403);
        $d = $request->validate(['reason' => 'required|string|max:2000']);
        $result = app(DeactivateResponsibilityAssignment::class)->handle($responsibility,
            new DeactivateResponsibilityAssignmentData($d['reason']), $request->user());

        return $this->show($request, $result);
    }
}
