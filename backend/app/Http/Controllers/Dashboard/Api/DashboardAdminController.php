<?php

namespace App\Http\Controllers\Dashboard\Api;

use App\Dashboard\DashboardApiReader;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DashboardAdminController extends Controller
{
    use ChecksDashboardRoles;

    public function tokens(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        return response()->json($reader->pluginTokens());
    }

    public function createToken(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array'],
            'scopes.*' => ['string'],
        ]);

        $id = (string) Str::ulid();
        $secret = Str::random(48);
        $prefix = 'devb_live_'.$id;
        $plainToken = $prefix.'|'.$secret;
        $now = now();

        DB::table('api_tokens')->insert([
            'id' => $id,
            'token_prefix' => $prefix,
            'token_hash' => hash('sha256', $secret),
            'user_id' => $request->user()->id,
            'device_id' => null,
            'name' => $validated['name'],
            'scopes' => json_encode($validated['scopes'], JSON_THROW_ON_ERROR),
            'expires_at' => $now->copy()->addDays(90),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $token = DB::table('api_tokens')
            ->leftJoin('users', 'users.id', '=', 'api_tokens.user_id')
            ->select([
                'api_tokens.id',
                'api_tokens.name',
                'api_tokens.token_prefix',
                'api_tokens.scopes',
                'api_tokens.created_at',
                'api_tokens.last_used_at',
                'api_tokens.revoked_at',
                'users.name as created_by',
            ])
            ->where('api_tokens.id', $id)
            ->first();

        return response()->json($reader->pluginToken($token, $plainToken));
    }

    public function rotateToken(Request $request, DashboardApiReader $reader, string $token): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        $tokenRow = DB::table('api_tokens')->where('id', $token)->first();
        abort_unless($tokenRow, 404);
        abort_if($tokenRow->revoked_at !== null, 409, 'Cannot rotate a revoked plugin token.');

        $secret = Str::random(48);
        $plainToken = $tokenRow->token_prefix.'|'.$secret;

        DB::table('api_tokens')->where('id', $token)->update([
            'token_hash' => hash('sha256', $secret),
            'last_used_at' => null,
            'updated_at' => now(),
        ]);

        $updated = DB::table('api_tokens')
            ->leftJoin('users', 'users.id', '=', 'api_tokens.user_id')
            ->select([
                'api_tokens.id',
                'api_tokens.name',
                'api_tokens.token_prefix',
                'api_tokens.scopes',
                'api_tokens.created_at',
                'api_tokens.last_used_at',
                'api_tokens.revoked_at',
                'users.name as created_by',
            ])
            ->where('api_tokens.id', $token)
            ->first();

        return response()->json($reader->pluginToken($updated, $plainToken));
    }

    public function revokeToken(Request $request, string $token): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        DB::table('api_tokens')->where('id', $token)->update([
            'revoked_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(null, 204);
    }

    public function devices(Request $request, DashboardApiReader $reader): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        return response()->json($reader->pluginDevices());
    }

    public function revokeDevice(Request $request, string $device): JsonResponse
    {
        $this->abortUnlessAdmin($request);

        abort_unless(DB::table('devices')->where('id', $device)->exists(), 404);

        DB::table('devices')->where('id', $device)->update([
            'status' => 'revoked',
            'updated_at' => now(),
        ]);

        return response()->json(null, 204);
    }

    private function abortUnlessAdmin(Request $request): void
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);
    }
}
