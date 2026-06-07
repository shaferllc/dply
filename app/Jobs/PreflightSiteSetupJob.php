<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\User;
use App\Services\Deploy\RuntimeDetection\GitCloner;
use App\Services\Sites\DotEnvFileWriter;
use App\Services\Sites\SiteEnvRequirementScanner;
use App\Services\SourceControl\GitIdentityResolver;
use App\Services\SourceControl\SourceControlRepositoryBrowser;
use App\Support\Sites\BootCriticalEnv;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Post-repo-connect pre-flight for `import`/`preset` sites (the setup-wizard
 * flow). Runs once when a repo is connected, BEFORE any first deploy exists:
 *
 *   1. Shallow-clones the repo into an ephemeral LOCAL temp dir on this worker
 *      (authenticated for provider-connected private repos).
 *   2. Scans the checkout for the env vars it expects
 *      ({@see SiteEnvRequirementScanner::scanLocalPath}) and caches the result
 *      in meta.env_requirements.
 *   3. Seeds the encrypted env cache from .env.example so the wizard's
 *      Environment step opens pre-filled with the author's contract.
 *   4. Decides:
 *        - HOLD   (always): stay live, mark needs_setup; the wizard walks the
 *          operator through environment variables, resource bindings, and
 *          review before they trigger the first deploy themselves.
 *        - FAILED (clone/scan error): fail OPEN — stay live, mark scan_failed
 *          with a classified reason so the wizard can guide the fix (re-scan,
 *          fix repo access). Never blind-deploys on an unread repo.
 *
 * The decision lives in meta.setup.state; the held condition itself is
 * otherwise derived (repo + never-deployed + missing required env) — see
 * Site::needsFirstDeploySetup().
 */
class PreflightSiteSetupJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * Required keys we never treat as "blocking" because the deploy supplies
     * them itself (APP_KEY is minted by `php artisan key:generate`).
     *
     * @var list<string>
     */
    private const AUTO_MANAGED_KEYS = ['APP_KEY'];

    public function __construct(
        public string $siteId,
        public ?string $userId = null,
    ) {}

    public function uniqueId(): string
    {
        return 'preflight-setup:'.$this->siteId;
    }

    public function handle(
        SiteEnvRequirementScanner $scanner,
        GitCloner $cloner,
        GitIdentityResolver $resolver,
        SourceControlRepositoryBrowser $browser,
        DotEnvFileWriter $envWriter,
    ): void {
        $site = Site::query()->with('server')->find($this->siteId);
        if ($site === null || $site->server === null) {
            return;
        }

        // Already deployed (re-run / race) — nothing to pre-flight.
        if ($site->last_deploy_at !== null) {
            return;
        }

        $repoUrl = trim((string) $site->git_repository_url);
        if ($repoUrl === '') {
            return;
        }
        $branch = trim((string) $site->git_branch) !== '' ? trim((string) $site->git_branch) : 'main';

        // Fresh console for this run.
        $this->resetScanConsole($site);

        // Outer guard: ANY unexpected failure must fail OPEN (state=scan_failed)
        // rather than leave the wizard polling 'scanning' forever — and be
        // visible in the console. The inner try handles clone/scan classification.
        try {
            $this->scanLog($site, "Pre-flight starting for {$repoUrl} (branch {$branch}).");

            // ── Step 1: Resolve access ─────────────────────────────────────────
            $this->markScanStep($site, 'resolving');
            $this->scanLog($site, 'Resolving repository access…');

            $accountId = data_get($site->meta, 'repository.git_source_control_account_id');
            $hasConnectedAccount = is_string($accountId) && trim($accountId) !== '' && $this->userId !== null;
            if ($hasConnectedAccount) {
                $this->scanLog($site, 'Connected provider account found — requesting authenticated clone URL.');
            } else {
                $this->scanLog($site, 'No connected provider account — using URL as-is (public repo or SSH key).');
            }

            $cloneUrl = $this->resolveCloneUrl($site, $repoUrl, $resolver, $browser);

            if ($hasConnectedAccount && $cloneUrl !== $repoUrl) {
                $this->scanLog($site, 'Authenticated clone URL obtained.');
            }
            $this->scanLog($site, 'Repository access resolved.');

            $tmpRoot = rtrim(sys_get_temp_dir(), '/').'/dply-preflight-'.bin2hex(random_bytes(6));
            $checkout = $tmpRoot.'/repo';

            try {
                // ── Step 2: Clone ──────────────────────────────────────────────
                $this->markScanStep($site, 'cloning');
                $this->scanLog($site, "Fetching branch '{$branch}' (shallow clone, depth 1)…");

                $cloneStart = microtime(true);
                $cloner->shallowClone($cloneUrl, $branch, $checkout);
                $cloneSecs = round(microtime(true) - $cloneStart, 1);
                $this->scanLog($site, "Clone complete in {$cloneSecs}s.");

                // ── Step 3: Scan ───────────────────────────────────────────────
                $this->markScanStep($site, 'scanning');
                $this->scanLog($site, 'Looking for .env.example, .env.local.example, or .env.dist…');

                $requirements = $scanner->scanLocalPath($checkout);
                $allKeys = $requirements['keys'] ?? [];

                $examplePath = $requirements['example_path'] ?? null;
                if ($examplePath !== null) {
                    $exampleFile = basename($examplePath);
                    $exampleCount = count(array_filter($allKeys, fn ($k) => in_array('example', $k['sources'] ?? [], true)));
                    $this->scanLog($site, "Found {$exampleFile} ({$exampleCount} declared key(s)).");
                } else {
                    $this->scanLog($site, 'No .env.example found — inferring required keys from code and config.');
                }

                $requiredCount = count(array_filter($allKeys, fn ($k) => (bool) ($k['required'] ?? false)));
                $optionalCount = count($allKeys) - $requiredCount;
                $codeCount = count(array_filter($allKeys, fn ($k) => in_array('code', $k['sources'] ?? [], true)));
                $configCount = count(array_filter($allKeys, fn ($k) => in_array('config', $k['sources'] ?? [], true)));
                $this->scanLog($site, sprintf(
                    'Scanned %d key(s) total: %d required, %d optional (%d from code, %d from config).',
                    count($allKeys), $requiredCount, $optionalCount, $codeCount, $configCount,
                ));

                // ── Step 4: Detect ─────────────────────────────────────────────
                $this->markScanStep($site, 'detecting');
                $this->scanLog($site, 'Identifying boot-critical environment requirements…');
            } catch (\Throwable $e) {
                // Fail OPEN: hold and route to setup with a classified reason.
                Log::warning('PreflightSiteSetupJob clone/scan failed', [
                    'site_id' => $this->siteId,
                    'error' => $e->getMessage(),
                ]);
                $this->scanLog($site, 'Clone/scan failed: '.$e->getMessage());
                $this->writeSetup($site, 'scan_failed', [
                    'error' => $this->classifyFailure($e->getMessage()),
                ]);

                return;
            } finally {
                try {
                    File::deleteDirectory($tmpRoot);
                } catch (\Throwable) {
                    // /tmp gets reaped by the OS; never shadow a real result.
                }
            }

            // ── Env cache pre-fill ─────────────────────────────────────────────
            $exampleValues = $this->exampleValues($requirements);
            if (blank($site->env_file_content) && $exampleValues !== []) {
                $this->scanLog($site, 'Pre-filling environment from .env.example defaults ('.count($exampleValues).' key(s)).');
                $site->forceFill([
                    'env_file_content' => $envWriter->render($exampleValues),
                ])->save();
            } elseif ($exampleValues === []) {
                $this->scanLog($site, 'No .env.example defaults to pre-fill.');
            } else {
                $this->scanLog($site, 'Environment already has values — skipping .env.example pre-fill.');
            }

            // ── Decision ──────────────────────────────────────────────────────
            $blocking = $this->blockingKeys($requirements);

            if ($blocking !== []) {
                $this->scanLog($site, count($blocking).' boot-critical variable(s) have no default and must be set before deploy:');
                foreach ($blocking as $key) {
                    $this->scanLog($site, "  → {$key}");
                }
            } else {
                $this->scanLog($site, 'All boot-critical variables have defaults or are auto-managed.');
            }

            $meta = is_array($site->meta) ? $site->meta : [];
            $meta['env_requirements'] = $requirements;

            if ($blocking === []) {
                // CLEAN — fire the first deploy. Stay live; the deploy runs on the
                // already-provisioned host.
                $this->scanLog($site, 'No blocking variables — starting first deploy now.');
                $meta['setup'] = [
                    'state' => 'deploying',
                    'scanned_at' => now()->toIso8601String(),
                ];
                $site->forceFill(['meta' => $meta])->save();

                $this->scanLog($site, 'Dispatching deploy pipeline…');
                $fresh = $site->fresh() ?? $site;
                $pipeline->seedRuntimeDefaults($fresh, (string) $fresh->runtime ?: 'php');
                $coordinator->dispatchManualForGroup($fresh->fresh() ?? $fresh);
                $this->scanLog($site, 'Deploy queued. Watch the Deployments tab for progress.');

                return;
            }

            // HOLD — missing blocking vars. Stay live; render the setup wizard.
            $this->scanLog($site, 'Setup wizard ready. Fill the required variable(s) above, then deploy.');
            $meta['setup'] = [
                'state' => 'needs_setup',
                'scanned_at' => now()->toIso8601String(),
                'blocking_count' => count($blocking),
                'blocking_keys' => $blocking,
            ];
            $site->forceFill(['meta' => $meta])->save();
        } catch (\Throwable $e) {
            Log::error('PreflightSiteSetupJob failed', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);
            $this->scanLog($site, 'Pre-flight error: '.$e->getMessage());
            $this->writeSetup($site, 'scan_failed', [
                'error' => $this->classifyFailure($e->getMessage()),
            ]);
        }
    }

    /**
     * Backstop for failures the handle() try/catch can't catch — a timeout
     * (SIGALRM), OOM, or a throw before/around handle(). Without this, an
     * exhausted job leaves the wizard polling 'scanning' forever.
     */
    public function failed(\Throwable $e): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }
        // Don't clobber a result the job already wrote.
        if (data_get($site->meta, 'setup.state') !== 'scanning') {
            return;
        }
        $this->scanLog($site, 'Pre-flight job failed: '.$e->getMessage());
        $this->writeSetup($site, 'scan_failed', [
            'error' => $this->classifyFailure($e->getMessage()),
        ]);
    }

    private function resolveCloneUrl(
        Site $site,
        string $repoUrl,
        GitIdentityResolver $resolver,
        SourceControlRepositoryBrowser $browser,
    ): string {
        $accountId = data_get($site->meta, 'repository.git_source_control_account_id');
        if (! is_string($accountId) || trim($accountId) === '' || $this->userId === null) {
            return $repoUrl;
        }

        $user = User::query()->find($this->userId);
        $identity = $user !== null ? $resolver->forId($user, $accountId) : null;
        if ($identity === null) {
            return $repoUrl;
        }

        return $browser->authenticatedCloneUrl($identity, $repoUrl);
    }

    /**
     * .env.example sample values (key => value) from the scan result.
     *
     * @param  array{keys: list<array{key: string, example: ?string, sources: list<string>, required: bool}>}  $requirements
     * @return array<string, string>
     */
    private function exampleValues(array $requirements): array
    {
        $values = [];
        foreach ($requirements['keys'] as $key) {
            if (in_array('example', $key['sources'], true)) {
                $values[$key['key']] = (string) ($key['example'] ?? '');
            }
        }

        return $values;
    }

    /**
     * Keys the user MUST supply before a first deploy can succeed: required,
     * with no usable example value (empty/placeholder), excluding the keys the
     * deploy mints itself — AND narrowed to the boot-critical set (framework URL
     * + database connection). The scanner marks hundreds of keys "required"
     * (everything in .env.example or a no-default env() call); holding the
     * deploy on all of them is unusable, so optional integrations never block —
     * they're surfaced in the wizard as advanced/optional instead.
     * See {@see \App\Support\Sites\BootCriticalEnv}.
     *
     * @param  array{keys: list<array{key: string, example: ?string, sources: list<string>, required: bool}>}  $requirements
     * @return list<string>
     */
    private function blockingKeys(array $requirements): array
    {
        $blocking = [];
        foreach ($requirements['keys'] as $key) {
            if (! $key['required']) {
                continue;
            }
            if (in_array($key['key'], self::AUTO_MANAGED_KEYS, true)) {
                continue;
            }
            if (! BootCriticalEnv::isBootCritical((string) $key['key'])) {
                continue;
            }
            $example = trim((string) ($key['example'] ?? ''));
            if ($example === '' || strtolower($example) === 'null') {
                $blocking[] = $key['key'];
            }
        }

        return $blocking;
    }

    private function classifyFailure(string $message): string
    {
        $m = strtolower($message);

        return match (true) {
            str_contains($m, 'authentication') || str_contains($m, 'permission denied') || str_contains($m, 'could not read') || str_contains($m, '403') => 'auth',
            str_contains($m, 'not found') || str_contains($m, 'repository') && str_contains($m, 'does not exist') || str_contains($m, '404') => 'not_found',
            str_contains($m, 'timed out') || str_contains($m, 'timeout') || str_contains($m, 'could not resolve host') => 'network',
            str_contains($m, 'branch') => 'branch',
            default => 'unknown',
        };
    }

    /**
     * Ordered scan phases the "Analyzing your repository…" view renders as a
     * live progress timeline. Keep in sync with site-setup.blade.php.
     *
     * @var list<string>
     */
    public const SCAN_STEPS = ['resolving', 'cloning', 'scanning', 'detecting'];

    /**
     * Record which scan phase is currently running into meta.setup.scan_step
     * (without disturbing state='scanning'), so the polling wizard can show a
     * step-by-step progress timeline instead of an opaque spinner.
     */
    private function markScanStep(Site $site, string $step): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $setup = is_array($meta['setup'] ?? null) ? $meta['setup'] : [];
        $setup['state'] = 'scanning';
        $setup['scan_step'] = $step;
        $setup['scan_step_at'] = now()->toIso8601String();
        $meta['setup'] = $setup;
        $site->forceFill(['meta' => $meta])->save();
    }

    /** Max console lines retained — newest win; older lines roll off. */
    private const SCAN_CONSOLE_MAX = 80;

    /**
     * Clear the live console at the start of a run so each (re-)scan starts
     * fresh. Stored at meta.setup_console (a sibling of meta.setup) so it
     * survives the setup state-machine rewrites in markScanStep/writeSetup.
     */
    private function resetScanConsole(Site $site): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['setup_console'] = [];
        $site->forceFill(['meta' => $meta])->save();
    }

    /**
     * Append a line to the live setup console (meta.setup_console). The
     * "Analyzing your repository…" view polls and renders these so an operator
     * can watch the pre-flight job — and SEE the reason when it stalls/fails.
     */
    private function scanLog(Site $site, string $line): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $log = is_array($meta['setup_console'] ?? null) ? array_values($meta['setup_console']) : [];
        $log[] = ['at' => now()->toIso8601String(), 'line' => $line];
        $meta['setup_console'] = array_slice($log, -self::SCAN_CONSOLE_MAX);
        $site->forceFill(['meta' => $meta])->save();
    }

    private function writeSetup(Site $site, string $state, array $extra = []): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['setup'] = array_merge([
            'state' => $state,
            'scanned_at' => now()->toIso8601String(),
        ], $extra);
        $site->forceFill(['meta' => $meta])->save();
    }
}
