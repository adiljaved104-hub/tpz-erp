<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Models\MobileDevice;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeviceController extends MobileController
{
    public function register(Request $request): JsonResponse
    {
        $d = $request->validate(['device_id' => 'required|uuid', 'expo_token' => ['required', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\\[[A-Za-z0-9_-]+\\]$/'], 'platform' => 'required|in:android,ios']);
        $user = $request->user();
        $hash = hash('sha256', $d['expo_token']);
        $device = DB::transaction(function () use ($d, $user, $hash) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $claimed = MobileDevice::query()->where('token_hash', $hash)->lockForUpdate()->first();
            abort_if($claimed !== null && $claimed->user_id !== $user->id, 409, 'This push token belongs to another active login. Sign out that device first.');
            $device = $claimed ?? MobileDevice::query()->where('user_id', $user->id)->where('device_id', $d['device_id'])->lockForUpdate()->first() ?? new MobileDevice;
            if ($claimed && $claimed->device_id !== $d['device_id']) {
                MobileDevice::query()->where('user_id', $user->id)->where('device_id', $d['device_id'])->whereKeyNot($claimed->id)->delete();
            }
            $device->user_id = $user->id;
            $device->personal_access_token_id = $user->currentAccessToken()->id;
            $device->device_id = $d['device_id'];
            $device->token_hash = $hash;
            $device->expo_token = $d['expo_token'];
            $device->platform = $d['platform'];
            $device->last_seen_at = now();
            $device->disabled_at = null;
            $device->save();

            return $device;
        }, 5);

        return response()->json(['data' => ['id' => $device->id, 'registered' => true]]);
    }

    public function unregister(Request $request): JsonResponse
    {
        $d = $request->validate(['device_id' => 'required|uuid']);
        MobileDevice::query()->where('user_id', $request->user()->id)->where('personal_access_token_id', $request->user()->currentAccessToken()->id)->where('device_id', $d['device_id'])->delete();

        return response()->json(['data' => ['registered' => false]]);
    }
}
