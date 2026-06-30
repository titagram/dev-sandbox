<?php

namespace App\Http\Middleware;

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
            return $exception->toResponse();
        }

        return $next($request);
    }
}
