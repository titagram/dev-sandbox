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
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $request->attributes->set('plugin_auth', $this->tokens->authenticateRequest($request));
        } catch (PluginTokenException $exception) {
            return $this->error($exception->errorCode, $exception->getMessage(), Response::HTTP_UNAUTHORIZED);
        }

        if ($request->header('X-DevBoard-Protocol') !== 'v1' || $request->input('protocol_version') !== 'v1') {
            return $this->error(
                'protocol_version_unsupported',
                'Unsupported plugin protocol version.',
                Response::HTTP_BAD_REQUEST,
                ['supported_versions' => ['v1']],
            );
        }

        return $next($request);
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
