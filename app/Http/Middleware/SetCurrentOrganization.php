<?php

namespace App\Http\Middleware;

use App\Models\Team;
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
                session()->forget('current_team_id');
            }
        }

        if (! session()->has('current_organization_id')) {
            $first = $user->organizations()->first();
            if ($first) {
                session(['current_organization_id' => $first->id]);
            }
        }

        $orgId = session('current_organization_id');
        if (! $orgId) {
            session()->forget('current_team_id');

            return $next($request);
        }

        $org = $user->organizations()->find($orgId);
        if (! $org || ! $org->hasMember($user)) {
            session()->forget('current_team_id');

            return $next($request);
        }

        $teamId = session('current_team_id');
        if ($teamId) {
            $valid = Team::query()
                ->whereKey($teamId)
                ->where('organization_id', $org->id)
                ->exists();
            if (! $valid) {
                session()->forget('current_team_id');
            }
        }

        return $next($request);
    }
}
