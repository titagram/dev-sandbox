<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PluginTokenController extends Controller
{
    use ChecksDashboardRoles;

    public function index(Request $request): Response
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);

        return Inertia::render('Admin/Tokens', [
            'dashboard' => [
                'user' => $this->dashboardUser($request->user()),
                'navigation' => $this->dashboardNavigation($request->user()),
            ],
            'tokens' => DB::table('api_tokens')
                ->join('users', 'users.id', '=', 'api_tokens.user_id')
                ->leftJoin('devices', 'devices.id', '=', 'api_tokens.device_id')
                ->select([
                    'api_tokens.id',
                    'api_tokens.token_prefix',
                    'api_tokens.name',
                    'api_tokens.scopes',
                    'api_tokens.last_used_at',
                    'api_tokens.revoked_at',
                    'users.email as user_email',
                    'devices.name as device_name',
                ])
                ->orderByDesc('api_tokens.created_at')
                ->get(),
            'devices' => DB::table('devices')
                ->join('users', 'users.id', '=', 'devices.user_id')
                ->leftJoin('api_tokens', 'api_tokens.device_id', '=', 'devices.id')
                ->select([
                    'devices.id',
                    'devices.name',
                    'devices.platform_os',
                    'devices.platform_arch',
                    'devices.plugin_version',
                    'devices.last_seen_at',
                    'devices.status',
                    'users.email as user_email',
                    DB::raw('count(api_tokens.id) as bound_token_count'),
                ])
                ->groupBy([
                    'devices.id',
                    'devices.name',
                    'devices.platform_os',
                    'devices.platform_arch',
                    'devices.plugin_version',
                    'devices.last_seen_at',
                    'devices.status',
                    'users.email',
                ])
                ->orderByDesc('devices.created_at')
                ->get()
                ->map(fn (object $device): array => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'platform_os' => $device->platform_os,
                    'platform_arch' => $device->platform_arch,
                    'plugin_version' => $device->plugin_version,
                    'last_seen_at' => $device->last_seen_at,
                    'status' => $device->status,
                    'user_email' => $device->user_email,
                    'bound_token_count' => (int) $device->bound_token_count,
                    'revoke_href' => "/admin/devices/{$device->id}",
                ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['required', 'array'],
            'scopes.*' => ['string'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:365'],
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
            'expires_at' => $now->copy()->addDays($validated['expires_in_days'] ?? 90),
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        app(AuditLogger::class)->record(
            'token.created',
            'api_token',
            $id,
            ['name' => $validated['name'], 'scopes' => $validated['scopes']],
            [
                'type' => 'user',
                'user_id' => $request->user()->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        return response()->json([
            'plain_token' => $plainToken,
            'token' => [
                'id' => $id,
                'name' => $validated['name'],
                'token_prefix' => $prefix,
                'scopes' => $validated['scopes'],
            ],
        ]);
    }

    public function destroy(Request $request, string $token): JsonResponse|RedirectResponse
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);

        DB::table('api_tokens')
            ->where('id', $token)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        app(AuditLogger::class)->record(
            'token.revoked',
            'api_token',
            $token,
            [],
            [
                'type' => 'user',
                'user_id' => $request->user()->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        );

        if ($request->expectsJson()) {
            return response()->json(['revoked' => true]);
        }

        return back(303);
    }

    public function rotate(Request $request, string $token): JsonResponse
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);

        $validated = $request->validate([
            'confirm_rotate' => ['nullable', 'boolean'],
        ]);

        if (! ($validated['confirm_rotate'] ?? false)) {
            return response()->json([
                'message' => 'The confirm rotate field must be accepted before token rotation.',
                'errors' => [
                    'confirm_rotate' => ['Explicit confirmation is required before token rotation.'],
                ],
            ], 422);
        }

        $tokenRow = DB::table('api_tokens')->where('id', $token)->first();
        abort_unless($tokenRow, 404);
        abort_if($tokenRow->revoked_at !== null, 409, 'Cannot rotate a revoked plugin token.');

        $secret = Str::random(48);
        $plainToken = $tokenRow->token_prefix.'|'.$secret;
        $now = now();

        DB::table('api_tokens')->where('id', $token)->update([
            'token_hash' => hash('sha256', $secret),
            'last_used_at' => null,
            'updated_at' => $now,
        ]);

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'token.rotated',
            'target_type' => 'api_token',
            'target_id' => $token,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => json_encode([
                'token_prefix' => $tokenRow->token_prefix,
                'device_id' => $tokenRow->device_id,
                'scopes' => json_decode($tokenRow->scopes, true, 512, JSON_THROW_ON_ERROR),
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        return response()->json([
            'plain_token' => $plainToken,
            'token' => [
                'id' => $tokenRow->id,
                'name' => $tokenRow->name,
                'token_prefix' => $tokenRow->token_prefix,
                'scopes' => json_decode($tokenRow->scopes, true, 512, JSON_THROW_ON_ERROR),
            ],
        ]);
    }

    public function revokeDevice(Request $request, string $device): JsonResponse
    {
        abort_unless($this->userHasRole($request->user(), 'Admin'), 403);

        $deviceRow = DB::table('devices')->where('id', $device)->first();
        abort_unless($deviceRow, 404);
        $now = now();

        DB::table('devices')
            ->where('id', $device)
            ->where('status', '!=', 'revoked')
            ->update([
                'status' => 'revoked',
                'updated_at' => $now,
            ]);

        DB::table('audit_logs')->insert([
            'id' => (string) Str::ulid(),
            'actor_user_id' => $request->user()->id,
            'actor_device_id' => null,
            'actor_type' => 'user',
            'action' => 'device.revoked',
            'target_type' => 'device',
            'target_id' => $device,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => json_encode([
                'device_name' => $deviceRow->name,
                'previous_status' => $deviceRow->status,
                'bound_token_count' => DB::table('api_tokens')->where('device_id', $device)->count(),
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
        ]);

        return response()->json(['revoked' => true]);
    }
}
