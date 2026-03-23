<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return $next($request);
        }

        $orgId = session('current_organization_id');
        $user = $request->user();

        if ($orgId) {
            $inOrg = $user->organizations()->where('organizations.id', $orgId)->exists();
            if (! $inOrg) {
                session()->forget('current_organization_id');
            }
        }

        if (! session()->has('current_organization_id')) {
            $first = $user->organizations()->first();
            if ($first) {
                session(['current_organization_id' => $first->id]);
            }
        }

        return $next($request);
    }
}
