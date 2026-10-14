<?php

namespace App\Http\Middleware;

use App\Enums\EmployeeRole;
use App\Filament\Resources\QcInspections\QcInspectionResource;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTechnicianQcOnlyWeb
{
    private const QC_LIVEWIRE_COMPONENT_PREFIX = 'app.filament.resources.qc-inspections.';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->employee?->role !== EmployeeRole::Technician) {
            return $next($request);
        }

        if ($request->routeIs('filament.admin.pages.dashboard') || $request->is('admin')) {
            return redirect()->to(QcInspectionResource::getUrl('index'));
        }

        if ($request->routeIs('filament.admin.resources.qc-inspections.*')) {
            return $next($request);
        }

        $routeName = (string) $request->route()?->getName();
        if (str_starts_with($routeName, 'filament.admin.')
            && (str_ends_with($routeName, '.logout') || str_contains($routeName, '.profile'))) {
            return $next($request);
        }

        if ($request->routeIs('livewire.update') && $this->containsOnlyQcComponents($request)) {
            return $next($request);
        }

        if ($request->routeIs('livewire.upload-file', 'livewire.preview-file')) {
            return $next($request);
        }

        abort(Response::HTTP_FORBIDDEN);
    }

    private function containsOnlyQcComponents(Request $request): bool
    {
        $components = $request->input('components');

        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = is_array($component) ? ($component['snapshot'] ?? null) : null;
            $decoded = is_string($snapshot) ? json_decode($snapshot, true) : null;
            $name = is_array($decoded) ? data_get($decoded, 'memo.name') : null;

            if (! is_string($name) || ! str_starts_with($name, self::QC_LIVEWIRE_COMPONENT_PREFIX)) {
                return false;
            }
        }

        return true;
    }
}
