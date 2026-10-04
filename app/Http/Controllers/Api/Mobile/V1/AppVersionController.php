<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use Illuminate\Http\JsonResponse;

class AppVersionController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['data' => [
            'latest_version' => (string) config('mobile.app.latest_version'),
            'latest_build' => (int) config('mobile.app.latest_build'),
            'minimum_build' => (int) config('mobile.app.minimum_build'),
            'update_required' => (bool) config('mobile.app.update_required'),
            'download_url' => (string) config('mobile.app.download_url'),
            'message' => (string) config('mobile.app.message'),
        ]])->header('Cache-Control', 'no-store');
    }
}
