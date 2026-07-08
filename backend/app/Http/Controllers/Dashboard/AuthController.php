<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Dashboard\Concerns\ChecksDashboardRoles;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AuthController extends Controller
{
    use ChecksDashboardRoles;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();

        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json($this->frontendUser($user));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json($this->frontendUser($user));
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    /**
     * @return array{id: string, name: string, email: string, role: string, avatar_color: string}
     */
    private function frontendUser(User $user): array
    {
        $role = $this->frontendRole($this->dashboardRoles($user));

        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'role' => $role,
            'avatar_color' => $this->avatarColor($role),
        ];
    }

    /**
     * @param list<string> $roles
     */
    private function frontendRole(array $roles): string
    {
        foreach ([
            'Admin' => 'admin',
            'PM' => 'pm',
            'Developer' => 'developer',
            'Sysadmin' => 'sysadmin',
            'Agent' => 'agent',
        ] as $backendRole => $frontendRole) {
            if (in_array($backendRole, $roles, true)) {
                return $frontendRole;
            }
        }

        return 'agent';
    }

    private function avatarColor(string $role): string
    {
        return match ($role) {
            'admin' => '#2563eb',
            'pm' => '#7c3aed',
            'developer' => '#059669',
            'sysadmin' => '#d97706',
            default => '#64748b',
        };
    }
}
