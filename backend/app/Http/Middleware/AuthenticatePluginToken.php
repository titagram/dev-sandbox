<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use App\Services\PluginRequestSigner;
use App\Services\PluginTokenException;
use App\Services\PluginTokenService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatePluginToken
{
    public function __construct(
        private readonly PluginTokenService $tokens,
        private readonly PluginRequestSigner $signer,
    ) {
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
            app(AuditLogger::class)->record(
                'permission.denied',
                'api_token',
                $auth['token']->id,
                ['missing_scopes' => $missingScopes, 'required_scopes' => $scopes],
                [
                    'type' => 'plugin',
                    'device_id' => $auth['token']->device_id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );

            return $this->error(
                'scope_missing',
                'Plugin token does not include the required scope.',
                Response::HTTP_FORBIDDEN,
                ['missing_scopes' => $missingScopes],
            );
        }

        if ($auth['token']->device_id !== null) {
            $device = $auth['device'];

            if ($device && $device->signing_secret_hash !== null) {
                $deviceId = $request->header('X-DevBoard-Device-Id');
                $timestamp = $request->header('X-DevBoard-Timestamp');
                $contentSha256 = $request->header('X-DevBoard-Content-SHA256');
                $signature = $request->header('X-DevBoard-Signature');

                if ($deviceId === null || $timestamp === null || $contentSha256 === null || $signature === null) {
                    return $this->error(
                        'device_signature_required',
                        'Device-bound plugin token requires a valid device signature.',
                        Response::HTTP_UNAUTHORIZED,
                    );
                }

                if ($deviceId !== $auth['token']->device_id) {
                    return $this->error(
                        'device_signature_invalid',
                        'Device ID in signature header does not match the token device binding.',
                        Response::HTTP_UNAUTHORIZED,
                    );
                }

                try {
                    $body = (string) $request->getContent();
                    $this->signer->verify(
                        $request->method(),
                        $request->getRequestUri(),
                        $body,
                        $device->signing_secret_hash,
                        (int) $timestamp,
                        $contentSha256,
                        $signature,
                    );
                } catch (PluginTokenException $exception) {
                    return $this->error($exception->errorCode, $exception->getMessage(), Response::HTTP_UNAUTHORIZED);
                }
            }
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

        if (str_starts_with((string) $request->header('Content-Type'), 'application/octet-stream')) {
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
