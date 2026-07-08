<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RegisterDeviceController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'fingerprint_hash' => ['required', 'string', 'max:255'],
            'platform_os' => ['required', 'string', 'max:64'],
            'platform_arch' => ['required', 'string', 'max:64'],
            'plugin_version' => ['required', 'string', 'max:64'],
        ]);

        $auth = $request->attributes->get('plugin_auth');
        $token = $auth['token'];
        $now = now();

        $device = DB::table('devices')
            ->where('user_id', $token->user_id)
            ->where('fingerprint_hash', $validated['fingerprint_hash'])
            ->first();

        if ($device) {
            if ($device->status !== 'active') {
                return response()->json([
                    'error' => [
                        'code' => 'device_required',
                        'message' => 'A registered active plugin device is required.',
                    ],
                ], Response::HTTP_UNAUTHORIZED);
            }

            DB::table('devices')->where('id', $device->id)->update([
                'name' => $validated['name'],
                'platform_os' => $validated['platform_os'],
                'platform_arch' => $validated['platform_arch'],
                'plugin_version' => $validated['plugin_version'],
                'status' => 'active',
                'last_seen_at' => $now,
                'updated_at' => $now,
            ]);

            $deviceId = $device->id;
        } else {
            $deviceId = (string) Str::ulid();

            DB::table('devices')->insert([
                'id' => $deviceId,
                'user_id' => $token->user_id,
                'name' => $validated['name'],
                'fingerprint_hash' => $validated['fingerprint_hash'],
                'platform_os' => $validated['platform_os'],
                'platform_arch' => $validated['platform_arch'],
                'plugin_version' => $validated['plugin_version'],
                'last_seen_at' => $now,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('api_tokens')->where('id', $token->id)->update([
            'device_id' => $deviceId,
            'last_used_at' => $now,
            'updated_at' => $now,
        ]);

        return response()->json([
            'device_id' => $deviceId,
            'status' => 'active',
            'server_time' => $now->toISOString(),
        ]);
    }
}
