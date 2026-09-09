<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Actions\Sites\ResetSiteApp;
use Illuminate\Support\Facades\Gate;

/**
 * "Reset this app and pick again" from the site Danger section.
 *
 * The same reset already existed in two places, but neither was reachable when
 * you simply wanted it: the Repository tab's danger zone (a tab that reads as
 * being about Git, not about the app), and the scaffold journey — which only
 * renders while an install is running or has failed. A working site had no
 * obvious way back to the picker.
 */
trait ManagesSiteAppReset
{
    public function resetSiteApp(ResetSiteApp $reset): void
    {
        Gate::authorize('update', $this->site);

        $reset->run($this->site, auth()->id() === null ? null : (string) auth()->id());

        $this->redirect(route('sites.choose-app', [
            'server' => $this->site->server_id,
            'site' => $this->site->id,
        ]), navigate: true);
    }

    /**
     * Whether there is an app to reset. Covers both shapes: a connected repo,
     * and a manage-in-place install (classic WordPress / Drupal) which has no
     * repo at all — gating on the repo alone is what hid this from exactly the
     * sites that needed it.
     */
    public function siteHasResettableApp(): bool
    {
        return trim((string) ($this->site->git_repository_url ?? '')) !== ''
            || data_get($this->site->meta, 'scaffold.framework') !== null;
    }
}
