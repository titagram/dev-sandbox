<?php

namespace App\Http\Controllers\Plugin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $auth = $request->attributes->get('plugin_auth');
        $token = $auth['token'];

        return response()->json([
            'protocol_version' => 'v1',
            'authenticated' => true,
            'token' => [
                'token_id' => $token->id,
                'token_prefix' => $token->token_prefix,
                'scopes' => json_decode($token->scopes, true, 512, JSON_THROW_ON_ERROR),
                'expires_at' => $token->expires_at,
                'device_id' => $token->device_id,
                'revoked' => $token->revoked_at !== null,
            ],
            'server_time' => now()->toISOString(),
        ]);
    }
}
