<?php

namespace App\Http\Middleware;

use App\Enums\EmployeeRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureTechnicianQcOnlyMobile
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->employee?->role !== EmployeeRole::Technician) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        $method = strtoupper($request->method());

        if ($method === 'GET' && in_array($path, [
            'api/mobile/v1/workspace/modules',
            'api/mobile/v1/workspace/manifest',
        ], true)) {
            return $next($request);
        }

        if (in_array($path, ['api/mobile/v1/devices'], true) && in_array($method, ['POST', 'DELETE'], true)) {
            return $next($request);
        }

        if (preg_match('~\Aapi/mobile/v1/workspace/qc(?:/|\z)~', $path) === 1) {
            return $next($request);
        }

        abort(Response::HTTP_FORBIDDEN);
    }
}
