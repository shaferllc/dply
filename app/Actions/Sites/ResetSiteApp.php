<?php

declare(strict_types=1);

namespace App\Actions\Sites;

use App\Jobs\ResetSiteToBlankJob;
use App\Livewire\Sites\Concerns\InteractsWithScaffoldJourney;
use App\Livewire\Sites\Concerns\ManagesRepositoryConnection;
use App\Models\Site;

/**
 * Remove a site's application and reopen the app picker.
 *
 * The site shell — server, domains, testing URL, certificates, bindings — is
 * always kept. Only the app goes: repo pointer, scaffold record, theme/plugin
 * repos, and the deployed code on the box.
 *
 * One action rather than three copies. This logic grew up inline in
 * {@see ManagesRepositoryConnection::disconnectAndStartOver()}
 * and again in
 * {@see InteractsWithScaffoldJourney::resetScaffoldAndChooseAgain()},
 * and the two had already drifted — only one of them cleared the status, which
 * is what actually decides whether the picker reopens.
 *
 * Callers own their own authorization and redirect; this only mutates state.
 */
class ResetSiteApp
{
    public function run(Site $site, ?string $byUserId = null): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];

        // Provider/branch hints belong to the repo being disconnected.
        foreach (['git_ref_kind', 'git_source_control_account_id', 'git_provider_kind', 'scaffold'] as $key) {
            unset($meta[$key]);
        }

        // The "skipped" sentinel is what makes Site::canRechooseApp() true.
        $meta['choose_app'] = [
            'skipped' => true,
            'reset_at' => now()->toIso8601String(),
            'reset_by_user_id' => $byUserId,
        ];

        $site->forceFill([
            // Clearing the status matters as much as clearing the meta:
            // lacksInstalledApp() short-circuits on SCAFFOLDING /
            // SCAFFOLD_FAILED, so a failed install would stay unresettable and
            // the picker would remain shut with the scaffold already gone.
            'status' => Site::STATUS_AWAITING_APP,
            'git_repository_url' => '',
            'git_branch' => 'main',
            'last_deploy_at' => null,
            'meta' => $meta,
        ])->save();

        // Theme/plugin repos belonged to the app being removed. Dropped rather
        // than torn down one by one: the job below removes the whole deployed
        // tree, which already contains every materialized theme and plugin.
        $site->gitSources()->delete();

        // Wipes the deployed code and restores the splash page. Also the only
        // way a half-written install (e.g. a broken wp-config.php) stops
        // poisoning the next installer.
        ResetSiteToBlankJob::dispatch((string) $site->id);
    }
}
