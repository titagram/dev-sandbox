<?php

namespace App\Http\Middleware;

use App\Services\PluginTokenException;
use App\Services\PluginTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePluginToken
{
    public function __construct(private readonly PluginTokenService $tokens)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        try {
            $auth = $this->tokens->authenticateRequest($request);
            $request->attributes->set('plugin_auth', $auth);
        } catch (PluginTokenException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        if (! $this->hasSupportedProtocol($request)) {
            return $this->error(
                'protocol_version_unsupported',
                'Unsupported plugin protocol version.',
                Response::HTTP_BAD_REQUEST,
                ['supported_versions' => ['v1']],
            );
        }

        $missingScopes = $this->missingScopes($auth['token']->scopes, $scopes);

        if ($missingScopes !== []) {
            return $this->error(
                'scope_missing',
                'Plugin token does not include the required scope.',
                Response::HTTP_FORBIDDEN,
                ['missing_scopes' => $missingScopes],
            );
        }

        return $next($request);
    }

    private function hasSupportedProtocol(Request $request): bool
    {
        if ($request->header('X-DevBoard-Protocol') !== 'v1') {
            return false;
        }

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return true;
        }

        return $request->input('protocol_version') === 'v1';
    }

    /**
     * @param array<int, string> $requiredScopes
     * @return list<string>
     */
    private function missingScopes(string $tokenScopesJson, array $requiredScopes): array
    {
        if ($requiredScopes === []) {
            return [];
        }

        $tokenScopes = json_decode($tokenScopesJson, true, 512, JSON_THROW_ON_ERROR);

        return array_values(array_diff($requiredScopes, $tokenScopes));
    }

    /**
     * @param array<string, mixed> $details
     */
    private function error(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json(['error' => $error], $status);
    }
}
