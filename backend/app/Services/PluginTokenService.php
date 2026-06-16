<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class PluginTokenService
{
    /**
     * @return array{token: object, user: object|null, device: object|null}
     *
     * @throws PluginTokenException
     */
    public function authenticateRequest(Request $request): array
    {
        $authorization = $request->header('Authorization', '');

        if (! str_starts_with($authorization, 'Bearer ')) {
            throw new PluginTokenException('unauthorized', 'Plugin token is required.');
        }

        return $this->authenticateToken(substr($authorization, 7));
    }

    /**
     * @return array{token: object, user: object|null, device: object|null}
     *
     * @throws PluginTokenException
     */
    public function authenticateToken(string $plainToken): array
    {
        [$tokenId, $prefix, $secret] = $this->parseToken($plainToken);

        $token = DB::table('api_tokens')
            ->where('id', $tokenId)
            ->where('token_prefix', $prefix)
            ->first();

        if (! $token) {
            throw new PluginTokenException('unauthorized', 'Plugin token is invalid.');
        }

        if ($token->revoked_at !== null) {
            throw new PluginTokenException('token_revoked', 'Plugin token has been revoked.');
        }

        if ($token->expires_at !== null && Carbon::parse($token->expires_at)->isPast()) {
            throw new PluginTokenException('unauthorized', 'Plugin token has expired.');
        }

        if (! hash_equals($token->token_hash, $this->hashSecret($secret))) {
            throw new PluginTokenException('unauthorized', 'Plugin token is invalid.');
        }

        DB::table('api_tokens')->where('id', $token->id)->update([
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);

        $token = DB::table('api_tokens')->where('id', $token->id)->first();
        $user = DB::table('users')->where('id', $token->user_id)->first();
        $device = $token->device_id
            ? DB::table('devices')->where('id', $token->device_id)->first()
            : null;

        return [
            'token' => $token,
            'user' => $user,
            'device' => $device,
        ];
    }

    public function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     *
     * @throws PluginTokenException
     */
    private function parseToken(string $plainToken): array
    {
        if (! str_starts_with($plainToken, 'devb_live_') || ! str_contains($plainToken, '|')) {
            throw new PluginTokenException('unauthorized', 'Plugin token is invalid.');
        }

        [$prefix, $secret] = explode('|', $plainToken, 2);
        $tokenId = substr($prefix, strlen('devb_live_'));

        if ($tokenId === '' || $secret === '') {
            throw new PluginTokenException('unauthorized', 'Plugin token is invalid.');
        }

        return [$tokenId, $prefix, $secret];
    }
}
