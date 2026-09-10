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
    /**
     * Confirmation step, using the same in-app modal every other danger action
     * uses rather than `wire:confirm`.
     *
     * The native dialog was wrong twice over: unstyled browser chrome in the
     * middle of a designed danger zone, and routing the copy through an HTML
     * attribute double-escaped the apostrophe — the operator was asked to wipe
     * "this site&#039;s application".
     */
    public function confirmResetSiteApp(): void
    {
        Gate::authorize('update', $this->site);

        $this->openConfirmActionModal(
            'resetSiteApp',
            [],
            __('Reset app and start over'),
            __('Wipes the deployed application and its env from the server, then reopens the app picker. The server, domains, testing URL, certificates and resource bindings are kept.'),
            __('Reset app'),
            true,
            null,
            null,
            '',
            false,
            __('This cannot be undone.'),
        );
    }

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
