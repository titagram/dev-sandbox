<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use App\Services\Hades\HadesTokenException;
use App\Services\Hades\HadesTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateHadesAgentToken
{
    public function __construct(private readonly HadesTokenService $tokens)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $auth = $this->tokens->authenticateAgentRequest($request);
            $request->attributes->set('hades_auth', $auth);
        } catch (HadesTokenException $exception) {
            app(AuditLogger::class)->record(
                'permission.denied',
                'hades_agent_token',
                null,
                ['error_code' => $exception->errorCode, 'message' => $exception->getMessage()],
                [
                    'type' => 'hades_agent',
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            );

            return $exception->toResponse();
        }

        return $next($request);
    }
}
