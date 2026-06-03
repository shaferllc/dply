<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Deploy\SiteDeployPipelineManager;
use App\Services\SshConnectionFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * "Optimize pipeline" — reads the deployed repo's package.json, composer.json
 * and lockfiles, infers the framework + build needs, and ADDS the deploy steps
 * the pipeline is missing (composer install, the right JS install for the
 * detected package manager, the front-end build, Laravel migrate/optimize/
 * storage:link, Horizon/Octane restarts). Idempotent: steps already in the
 * pipeline are left alone. Streams what it added to the console banner.
 */
class OptimizeSitePipelineJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public string $consoleActionId,
        public string $siteId,
    ) {}

    public function handle(SshConnectionFactory $factory, SiteDeployPipelineManager $pipelines): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        $action = ConsoleAction::query()->find($this->consoleActionId);
        if ($site === null || $action === null || $site->server === null) {
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
            $emit->step('scan', 'Reading package.json / composer.json in '.$dir.' …');
            $conn = $factory->forServer($site->server);
            if (! $conn->connect(12)) {
                throw new \RuntimeException('Could not open SSH to '.(string) $site->server->name.'.');
            }

            $pkg = $this->readJson($conn, $dir.'/package.json');
            $composer = $this->readJson($conn, $dir.'/composer.json');
            $locks = $this->detectLocks($conn, $dir);

            $needed = $this->plan($pkg, $composer, $locks, $emit);
            if ($needed === []) {
                $emit->success('scan', 'No pipeline changes needed — your pipeline already covers what the repo requires.');
                $this->complete(failed: false);

                return;
            }

            $pipeline = $pipelines->ensureDefaultPipeline($site);
            $existing = $pipeline->steps()->get();
            $existingTypes = $existing->pluck('step_type')->map(static fn ($t): string => (string) $t)->all();
            $existingCustom = $existing->where('step_type', SiteDeployStep::TYPE_CUSTOM)
                ->map(static fn ($s): string => strtolower((string) $s->custom_command));

            $added = 0;
            foreach ($needed as $step) {
                $isCustom = $step['type'] === SiteDeployStep::TYPE_CUSTOM;
                $already = $isCustom
                    ? $existingCustom->contains(static fn (string $c): bool => $c === strtolower((string) $step['command']))
                    : in_array($step['type'], $existingTypes, true);

                if ($already) {
                    continue;
                }

                $pipelines->addStep($pipeline, $step['type'], $step['command'] ?? null, 900, null, $step['phase']);
                $existingTypes[] = $step['type'];
                if ($isCustom) {
                    $existingCustom->push(strtolower((string) $step['command']));
                }
                $emit->success('add', 'Added: '.$step['label']);
                $added++;
            }

            $emit->step('scan', $added > 0
                ? $added.' step(s) added — review them on the Pipeline tab, then deploy.'
                : 'Everything the repo needs is already in the pipeline.');
            $this->complete(failed: false);
        } catch (\Throwable $e) {
            $emit->error('Pipeline optimize failed: '.mb_substr($e->getMessage(), 0, 300), 'scan');
            $this->complete(failed: true, error: mb_substr($e->getMessage(), 0, 500));
        } finally {
            try {
                $conn?->disconnect();
            } catch (\Throwable) {
                // best-effort cleanup
            }
        }
    }

    /**
     * Build the ordered list of steps the repo needs.
     *
     * @param  array<string, mixed>|null  $pkg
     * @param  array<string, mixed>|null  $composer
     * @param  list<string>  $locks
     * @return list<array{type: string, phase: string, command: ?string, label: string}>
     */
    private function plan(?array $pkg, ?array $composer, array $locks, ConsoleEmitter $emit): array
    {
        $needed = [];

        $require = is_array($composer['require'] ?? null) ? array_change_key_case($composer['require'], CASE_LOWER) : [];
        $isLaravel = isset($require['laravel/framework']);
        $hasComposer = $composer !== null || in_array('composer.lock', $locks, true);
        $scripts = is_array($pkg['scripts'] ?? null) ? $pkg['scripts'] : [];

        $emit->step('scan', sprintf('Detected: %s%s%s',
            $isLaravel ? 'Laravel' : ($hasComposer ? 'PHP/Composer' : 'no Composer'),
            $pkg !== null ? ', Node ('.$this->packageManager($locks).')' : ', no Node',
            $isLaravel && isset($require['laravel/horizon']) ? ', Horizon' : '',
        ));

        // --- build phase ---
        if ($hasComposer) {
            $needed[] = ['type' => SiteDeployStep::TYPE_COMPOSER_INSTALL, 'phase' => SiteDeployStep::PHASE_BUILD, 'command' => null, 'label' => 'Composer install'];
        }
        if ($pkg !== null) {
            [$installType, $installLabel] = $this->jsInstall($locks);
            $needed[] = ['type' => $installType, 'phase' => SiteDeployStep::PHASE_BUILD, 'command' => null, 'label' => $installLabel];

            $buildScript = isset($scripts['build']) ? 'build' : (isset($scripts['prod']) ? 'prod' : null);
            if ($buildScript !== null) {
                $pm = $this->packageManager($locks);
                $cmd = $pm === 'npm' ? "npm run {$buildScript}" : ($pm === 'bun' ? "bun run {$buildScript}" : "{$pm} {$buildScript}");
                $needed[] = ['type' => SiteDeployStep::TYPE_CUSTOM, 'phase' => SiteDeployStep::PHASE_BUILD, 'command' => $cmd, 'label' => 'Build assets ('.$cmd.')'];
            }
        }

        // --- release phase (Laravel) ---
        if ($isLaravel) {
            $needed[] = ['type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE, 'phase' => SiteDeployStep::PHASE_RELEASE, 'command' => null, 'label' => 'Run migrations'];
            $needed[] = ['type' => SiteDeployStep::TYPE_ARTISAN_OPTIMIZE, 'phase' => SiteDeployStep::PHASE_RELEASE, 'command' => null, 'label' => 'Cache config & routes (optimize)'];
            $needed[] = ['type' => SiteDeployStep::TYPE_ARTISAN_STORAGE_LINK, 'phase' => SiteDeployStep::PHASE_RELEASE, 'command' => null, 'label' => 'Link public storage'];
            if (isset($require['laravel/horizon'])) {
                $needed[] = ['type' => SiteDeployStep::TYPE_ARTISAN_HORIZON_TERMINATE, 'phase' => SiteDeployStep::PHASE_RELEASE, 'command' => null, 'label' => 'Restart Horizon'];
            }
            if (isset($require['laravel/octane'])) {
                $needed[] = ['type' => SiteDeployStep::TYPE_CUSTOM, 'phase' => SiteDeployStep::PHASE_RELEASE, 'command' => 'php artisan octane:reload', 'label' => 'Reload Octane'];
            }
        }

        return $needed;
    }

    /**
     * @param  list<string>  $locks
     * @return array{0: string, 1: string}
     */
    private function jsInstall(array $locks): array
    {
        return match (true) {
            in_array('yarn.lock', $locks, true) => [SiteDeployStep::TYPE_YARN_INSTALL, 'Yarn install'],
            in_array('pnpm-lock.yaml', $locks, true) => [SiteDeployStep::TYPE_PNPM_INSTALL, 'pnpm install'],
            in_array('bun.lockb', $locks, true) => [SiteDeployStep::TYPE_BUN_INSTALL, 'Bun install'],
            in_array('package-lock.json', $locks, true) => [SiteDeployStep::TYPE_NPM_CI, 'npm ci'],
            default => [SiteDeployStep::TYPE_NPM_INSTALL, 'npm install'],
        };
    }

    /**
     * @param  list<string>  $locks
     */
    private function packageManager(array $locks): string
    {
        return match (true) {
            in_array('yarn.lock', $locks, true) => 'yarn',
            in_array('pnpm-lock.yaml', $locks, true) => 'pnpm',
            in_array('bun.lockb', $locks, true) => 'bun',
            default => 'npm',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson($conn, string $path): ?array
    {
        $raw = $conn->exec('cat '.escapeshellarg($path).' 2>/dev/null', 15);
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<string>
     */
    private function detectLocks($conn, string $dir): array
    {
        $cmd = 'cd '.escapeshellarg($dir).' 2>/dev/null && for f in package-lock.json yarn.lock pnpm-lock.yaml bun.lockb composer.lock; do [ -f "$f" ] && echo "$f"; done';
        $out = $conn->exec($cmd, 15);

        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $out) ?: [])));
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
