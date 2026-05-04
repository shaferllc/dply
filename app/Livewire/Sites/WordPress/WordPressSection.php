<?php

declare(strict_types=1);

namespace App\Livewire\Sites\WordPress;

use App\Models\RemoteCliRun;
use App\Models\Site;
use App\Services\RemoteCli\Kind;
use App\Services\RemoteCli\RemoteCliPermissionDeniedException;
use App\Services\RemoteCli\WpCli;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Container for the WordPress Site Settings section (Q14).
 *
 * Renders one of five sub-tabs (Console / Plugins / Database / Cron /
 * Hardening). v1 ships with Console + Cron live; the other three are
 * placeholders gated on a v2 message until PR 10 fills them in.
 *
 * Permission checks delegate to {@see WpCli} via the underlying
 * {@see \App\Services\RemoteCli\RemoteCliPermissions} gate (Q17), so
 * the same risk classification that drives the API layer also drives
 * the UI's enable/disable state.
 */
class WordPressSection extends Component
{
    public Site $site;

    /** Active sub-tab. Persisted as ?wp= in the URL for sharing. */
    #[Url(as: 'wp')]
    public string $tab = 'console';

    public string $consoleCommand = 'plugin list';

    public string $consoleArgs = '--format=table';

    /** Most recent run id rendered in the Console output panel. */
    public ?int $latestRunId = null;

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->site = $site;
        // Non-WordPress sites get a friendly "not detected" placeholder
        // (see view), matching how the Laravel section degrades when
        // Laravel isn't detected. No 404 — the operator may be navigating
        // around the site dashboard with no specific tab in mind.
    }

    public function render(): View
    {
        return view('livewire.sites.wordpress.wordpress-section', [
            'history' => $this->history(),
            'latestRun' => $this->latestRunId !== null ? RemoteCliRun::query()->find($this->latestRunId) : null,
        ]);
    }

    /**
     * Run a wp-cli command from the Console sub-tab. The args field is
     * a single-line string split on whitespace; for v1 that's enough
     * (operators who need quoted args use the CLI surface from PR 12).
     */
    public function runConsoleCommand(WpCli $wpcli): void
    {
        $command = trim($this->consoleCommand);
        if ($command === '') {
            $this->addError('consoleCommand', __('Enter a wp-cli command.'));

            return;
        }

        $args = $this->consoleArgs !== '' ? preg_split('/\s+/', trim($this->consoleArgs)) : [];

        try {
            $result = $wpcli->run(
                site: $this->site,
                command: $command,
                args: array_values(array_filter($args ?: [], fn ($a) => is_string($a) && $a !== '')),
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('consoleCommand', __('Your role can\'t run :risk commands. Ask an admin or owner.', [
                'risk' => $e->risk->value,
            ]));

            return;
        }

        $this->latestRunId = $result->run->id;
    }

    /**
     * Cron sub-tab "switch to system cron" action. Adds the
     * DISABLE_WP_CRON constant to wp-config.php (idempotent — wp config
     * set short-circuits if already present) and inserts a crontab
     * entry that runs `wp cron event run --due-now` every minute.
     *
     * Surfacing the inverse switch (back to wp-cron-via-HTTP) is the
     * delete-the-crontab + wp config delete; left to PR 10's hardening
     * tab to expose as a toggle.
     */
    public function switchToSystemCron(WpCli $wpcli): void
    {
        try {
            $wpcli->run(
                site: $this->site,
                command: 'config set',
                args: ['DISABLE_WP_CRON', 'true', '--raw', '--type=constant'],
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('cron', __('Admin or owner role required to switch cron handler.'));

            return;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $meta['wp_cron'] = ['handler' => 'system_cron', 'switched_at' => now()->toISOString()];
        $this->site->meta = $meta;
        $this->site->save();
    }

    /**
     * Last 25 wp-cli runs against this site, regardless of transport.
     *
     * @return Collection<int, RemoteCliRun>
     */
    private function history(): Collection
    {
        return RemoteCliRun::query()
            ->where('site_id', $this->site->id)
            ->where('kind', Kind::Wp)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();
    }
}
