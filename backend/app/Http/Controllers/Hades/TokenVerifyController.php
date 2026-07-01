<?php

namespace App\Http\Controllers\Hades;

use App\Http\Controllers\Controller;
use App\Services\Hades\HadesTokenException;
use App\Services\Hades\HadesTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenVerifyController extends Controller
{
    public function __construct(private readonly HadesTokenService $tokens) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'string'],
        ]);

        try {
            $auth = $this->tokens->authenticateBootstrapToken(
                (string) $request->bearerToken(),
                $validated['project_id'],
            );
        } catch (HadesTokenException $exception) {
            return $exception->toResponse();
        }

        $token = $auth['token'];

        return response()->json([
            'protocol_version' => 'v1',
            'project_id' => $token->project_id,
            'token' => [
                'token_id' => $token->id,
                'token_prefix' => $token->token_prefix,
                'type' => 'bootstrap',
                'scopes' => json_decode($token->scopes, true, 512, JSON_THROW_ON_ERROR),
                'expires_at' => $token->expires_at,
                'revoked' => $token->revoked_at !== null,
            ],
            'capabilities' => [
                'registration' => true,
            ],
            'server_time' => now()->toISOString(),
        ]);
    }
}
