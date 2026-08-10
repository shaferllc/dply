<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Modules\Realtime\Models\RealtimeApp;
use Illuminate\Http\RedirectResponse;

/**
 * Redirects legacy `/organizations/{organization}/…` product URLs to their
 * session-scoped replacements, switching the active organization on the way.
 *
 * This exists instead of `Route::redirect` because the two URL shapes carry
 * different amounts of information. The old URL names its organization; the
 * new one reads `session('current_organization_id')`. A dumb redirect would
 * send someone's bookmark for org B straight to org A's page and render the
 * wrong org's data under a right-looking heading — a silent wrong answer,
 * which is worse than a 404.
 *
 * So: authorize membership first (a redirect must never become a way to probe
 * which org ids exist), switch the session, then redirect. Bookmarks keep
 * working and keep meaning what they meant.
 *
 * Lives in the shell rather than the Realtime module because legacy URLs are a
 * routing concern of the organizations group, and shell → module is the
 * permitted direction (deptrac.yaml).
 *
 * See docs/adr/managed-services-tier.md, decision 8.
 */
class OrgScopedRedirectController extends Controller
{
    /** `/organizations/{org}/realtime` → `/realtime` */
    public function realtime(Organization $organization): RedirectResponse
    {
        $this->switchTo($organization);

        return redirect()->route('realtime.index', [], 301);
    }

    /** `/organizations/{org}/realtime/{app}` → `/realtime/{app}` */
    public function realtimeApp(Organization $organization, RealtimeApp $realtimeApp): RedirectResponse
    {
        $this->authorize('view', $organization);

        // Checked before the session switch: org A's id paired with org B's app
        // must 404, not quietly re-home the session and then resolve against
        // whichever org won.
        abort_unless($realtimeApp->organization_id === $organization->id, 404);

        $this->switchTo($organization);

        return redirect()->route('realtime.show', ['realtimeApp' => $realtimeApp], 301);
    }

    private function switchTo(Organization $organization): void
    {
        $this->authorize('view', $organization);

        session(['current_organization_id' => (string) $organization->id]);
    }
}
