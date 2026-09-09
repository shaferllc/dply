<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Site;
use App\Support\Sites\SiteDaemonAdvisor;
use Illuminate\Support\Facades\Gate;

/**
 * Dismiss / restore actions for the "Suggested processes" panel.
 *
 * Lives in a trait because the panel renders from two unrelated components —
 * the site Laravel workspace and the server Daemons workspace — and both need
 * the same two verbs. The dismissal itself is persisted on the site
 * ({@see SiteDaemonAdvisor::dismiss()}), so it survives a reload and follows
 * the site across both surfaces.
 *
 * The consuming component must expose the target site via
 * {@see daemonSuggestionSite()}.
 */
trait DismissesDaemonSuggestions
{
    /**
     * The site the suggestions belong to. Components where the site is
     * contextual (e.g. the server Daemons workspace) override this.
     */
    protected function daemonSuggestionSite(): ?Site
    {
        return $this->site;
    }

    public function dismissDaemonSuggestion(string $key): void
    {
        $site = $this->daemonSuggestionSite();
        if ($site === null) {
            return;
        }

        Gate::authorize('update', $site);
        SiteDaemonAdvisor::dismiss($site, $key);

        // Re-read so the panel recomputes against the persisted meta.
        $this->refreshDaemonSuggestionSite($site);
    }

    public function restoreDaemonSuggestions(): void
    {
        $site = $this->daemonSuggestionSite();
        if ($site === null) {
            return;
        }

        Gate::authorize('update', $site);
        SiteDaemonAdvisor::restoreAll($site);

        $this->refreshDaemonSuggestionSite($site);
    }

    private function refreshDaemonSuggestionSite(Site $site): void
    {
        $fresh = $site->fresh();
        if ($fresh === null) {
            return;
        }

        if (property_exists($this, 'site') && $this->site instanceof Site && $this->site->is($site)) {
            $this->site = $fresh;
        }
    }
}
