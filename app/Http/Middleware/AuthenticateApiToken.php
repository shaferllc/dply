<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plaintext = $this->getTokenFromRequest($request);

        if (! $plaintext) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $token = ApiToken::findTokenByPlaintext($plaintext);

        if (! $token) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        if (! $token->isValid()) {
            return response()->json(['message' => 'Token expired or invalid'], 401);
        }

        $token->touchLastUsed();

        auth()->setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);
        $request->attributes->set('api_organization', $token->organization);

        return $next($request);
    }

    protected function getTokenFromRequest(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return $m[1];
        }

        return $request->header('X-API-Key');
    }
}
