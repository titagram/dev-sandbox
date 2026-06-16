<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
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

        if ($request->expectsJson()) {
            return response()->json(['revoked' => true]);
        }

        return back(303);
    }
}
