<?php

namespace App\Services\Hades;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class HadesTokenService
{
    private const BOOTSTRAP_PREFIX = 'hades_bootstrap_';
    private const AGENT_PREFIX = 'hades_agent_';

    /**
     * @return array{token: object}
     *
     * @throws HadesTokenException
     */
    public function authenticateBootstrapToken(string $plainToken, string $projectId): array
    {
        [$type, $tokenId, $prefix, $secret] = $this->parseToken($plainToken);

        if ($type !== 'bootstrap') {
            throw new HadesTokenException('wrong_token_type', 'A Hades bootstrap token is required.');
        }

        $token = DB::table('hades_bootstrap_tokens')
            ->where('id', $tokenId)
            ->where('token_prefix', $prefix)
            ->first();

        if (! $token) {
            throw new HadesTokenException('unauthorized', 'Hades bootstrap token is invalid.');
        }

        if ($token->project_id !== $projectId) {
            throw new HadesTokenException('token_project_mismatch', 'Hades bootstrap token is scoped to a different project.');
        }

        $this->validateStoredToken($token, $secret, 'bootstrap');
        $this->requireScope($token->scopes, 'hades.bootstrap');

        DB::table('hades_bootstrap_tokens')->where('id', $token->id)->update([
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'token' => DB::table('hades_bootstrap_tokens')->where('id', $token->id)->first(),
        ];
    }

    /**
     * @return array{token: object, agent: object}
     *
     * @throws HadesTokenException
     */
    public function authenticateAgentRequest(Request $request): array
    {
        $authorization = $request->header('Authorization', '');

        if (! str_starts_with($authorization, 'Bearer ')) {
            throw new HadesTokenException('unauthorized', 'Hades agent token is required.');
        }

        return $this->authenticateAgentToken(substr($authorization, 7));
    }

    /**
     * @return array{token: object, agent: object}
     *
     * @throws HadesTokenException
     */
    public function authenticateAgentToken(string $plainToken): array
    {
        [$type, $tokenId, $prefix, $secret] = $this->parseToken($plainToken);

        if ($type !== 'agent') {
            throw new HadesTokenException('wrong_token_type', 'A Hades agent token is required.');
        }

        $token = DB::table('hades_agent_tokens')
            ->where('id', $tokenId)
            ->where('token_prefix', $prefix)
            ->first();

        if (! $token) {
            throw new HadesTokenException('unauthorized', 'Hades agent token is invalid.');
        }

        $this->validateStoredToken($token, $secret, 'agent');
        $this->requireScope($token->scopes, 'hades.agent');

        $agent = DB::table('hades_agents')->where('id', $token->hades_agent_id)->first();

        if (! $agent || $agent->status !== 'active') {
            throw new HadesTokenException('agent_inactive', 'Hades agent is not active.');
        }

        DB::table('hades_agent_tokens')->where('id', $token->id)->update([
            'last_used_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hades_agents')->where('id', $agent->id)->update([
            'last_seen_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'token' => DB::table('hades_agent_tokens')->where('id', $token->id)->first(),
            'agent' => DB::table('hades_agents')->where('id', $agent->id)->first(),
        ];
    }

    /**
     * @return array{id: string, plain_token: string}
     */
    public function createAgentToken(object $agent): array
    {
        $id = (string) Str::ulid();
        $secret = Str::random(64);
        $prefix = self::AGENT_PREFIX.$id;
        $now = now();

        DB::table('hades_agent_tokens')->insert([
            'id' => $id,
            'project_id' => $agent->project_id,
            'hades_agent_id' => $agent->id,
            'token_prefix' => $prefix,
            'token_hash' => $this->hashSecret($secret),
            'name' => 'Hades agent token for '.$agent->external_agent_id,
            'scopes' => json_encode(['hades.agent'], JSON_THROW_ON_ERROR),
            'expires_at' => null,
            'revoked_at' => null,
            'last_used_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => $id,
            'plain_token' => $prefix.'|'.$secret,
        ];
    }

    public function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     *
     * @throws HadesTokenException
     */
    private function parseToken(string $plainToken): array
    {
        if (! str_contains($plainToken, '|')) {
            throw new HadesTokenException('unauthorized', 'Hades token is invalid.');
        }

        [$prefix, $secret] = explode('|', $plainToken, 2);

        if ($secret === '') {
            throw new HadesTokenException('unauthorized', 'Hades token is invalid.');
        }

        if (str_starts_with($prefix, self::BOOTSTRAP_PREFIX)) {
            $tokenId = substr($prefix, strlen(self::BOOTSTRAP_PREFIX));

            return ['bootstrap', $tokenId, $prefix, $secret];
        }

        if (str_starts_with($prefix, self::AGENT_PREFIX)) {
            $tokenId = substr($prefix, strlen(self::AGENT_PREFIX));

            return ['agent', $tokenId, $prefix, $secret];
        }

        throw new HadesTokenException('unauthorized', 'Hades token is invalid.');
    }

    /**
     * @throws HadesTokenException
     */
    private function validateStoredToken(stdClass $token, string $secret, string $label): void
    {
        if ($token->revoked_at !== null) {
            throw new HadesTokenException('token_revoked', "Hades {$label} token has been revoked.");
        }

        if ($token->expires_at !== null && Carbon::parse($token->expires_at)->isPast()) {
            throw new HadesTokenException('token_expired', "Hades {$label} token has expired.");
        }

        if (! hash_equals($token->token_hash, $this->hashSecret($secret))) {
            throw new HadesTokenException('unauthorized', "Hades {$label} token is invalid.");
        }
    }

    /**
     * @throws HadesTokenException
     */
    private function requireScope(string $scopesJson, string $scope): void
    {
        $scopes = json_decode($scopesJson, true, 512, JSON_THROW_ON_ERROR);

        if (! in_array($scope, $scopes, true)) {
            throw new HadesTokenException('scope_missing', 'Hades token does not include the required scope.', Response::HTTP_FORBIDDEN);
        }
    }
}
