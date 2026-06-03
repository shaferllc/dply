<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnectionFactory;
use App\Support\Sites\SiteFixers;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Runs one whitelisted "smart fixer" ({@see SiteFixers}) on a site's server over
 * SSH — migrate, install a missing PHP driver / client binary / Node, build the
 * front-end, composer install, fix permissions, clear caches, … Output + exit
 * status stream into the console banner.
 *
 * The UI passes a fixer KEY, never a command, so the runnable set is exactly
 * what {@see SiteFixers::all()} declares.
 */
class RunSiteFixerJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 660;

    public int $tries = 1;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
        public string $fixerKey,
    ) {}

    public function handle(SshConnectionFactory $factory): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        $action = ConsoleAction::query()->find($this->consoleActionId);
        $spec = SiteFixers::spec($this->fixerKey);

        if ($site === null || $action === null || $spec === null || $site->server === null) {
            return;
        }

        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        $emit = new ConsoleEmitter($this->consoleActionId);
        $dir = rtrim($site->effectiveEnvDirectory(), '/');
        $conn = null;

        try {
            $label = (string) $spec['label'];
            $emit->step('fix', $label.' — running on '.(string) $site->server->name.' …');

            $conn = $factory->forServer($site->server);
            if (! $conn->connect(12)) {
                throw new \RuntimeException('Could not open SSH to '.(string) $site->server->name.'.');
            }

            // Compose the inner command: artisan subcommand or a raw shell line,
            // optionally run inside the deploy directory.
            $inner = $spec['kind'] === 'artisan'
                ? 'php artisan '.$spec['command']
                : (string) $spec['command'];
            if (! empty($spec['cwd'])) {
                $inner = 'cd '.escapeshellarg($dir).' && '.$inner;
            }
            $payload = $inner.'; echo "DPLY_EXIT=$?"';

            // Run through a login shell so PATH includes node/composer/etc.
            $cmd = (! empty($spec['sudo']) ? 'sudo -n ' : '').'bash -lc '.escapeshellarg($payload);

            $out = $conn->exec($cmd, (int) ($spec['timeout'] ?? 300));

            $exit = null;
            if (preg_match('/DPLY_EXIT=(\d+)/', $out, $m) === 1) {
                $exit = (int) $m[1];
            }
            $clean = trim(preg_replace('/DPLY_EXIT=\d+\s*$/', '', $out) ?? $out);
            if ($clean !== '') {
                $emit->step('fix', $clean);
            }

            // Chain hint: a fix can reveal the next missing thing (e.g. migrate
            // → psql not found). Surface the follow-up fixer if one matches.
            foreach (SiteFixers::detect($out) as $next) {
                if ($next['key'] !== $this->fixerKey) {
                    $emit->step('fix', '→ Next: '.$next['label'].' — '.$next['reason']);
                }
            }

            if ($exit === 0) {
                $emit->success('fix', $label.' completed.');
                $this->complete(failed: false);
            } else {
                $emit->error($label.' failed'.($exit !== null ? ' (exit '.$exit.')' : '').'.', 'fix');
                $this->complete(failed: true, error: $label.' failed');
            }
        } catch (\Throwable $e) {
            $emit->error(((string) $spec['label']).' could not run: '.mb_substr($e->getMessage(), 0, 300), 'fix');
            $this->complete(failed: true, error: mb_substr($e->getMessage(), 0, 500));
        } finally {
            try {
                $conn?->disconnect();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
    }

    private function complete(bool $failed, ?string $error = null): void
    {
        DB::table('console_actions')->where('id', $this->consoleActionId)->update([
            'status' => $failed ? ConsoleAction::STATUS_FAILED : ConsoleAction::STATUS_COMPLETED,
            'finished_at' => now(),
            'error' => $failed ? $error : null,
            'updated_at' => now(),
        ]);
    }
}
