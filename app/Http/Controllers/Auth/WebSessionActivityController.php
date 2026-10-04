<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Security\WebInactivityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WebSessionActivityController extends Controller
{
    public function store(Request $request, WebInactivityService $inactivity): JsonResponse
    {
        abort_unless($request->header('X-TPZ-User-Activity') === '1', 400);

        $lastActivityAt = $inactivity->record($request);

        return response()->json([
            'expires_at' => $lastActivityAt + $inactivity->timeoutSeconds(),
        ]);
    }

    public function show(Request $request, WebInactivityService $inactivity): JsonResponse
    {
        return response()->json([
            'expires_at' => $inactivity->expiresAt($request),
        ]);
    }
}
