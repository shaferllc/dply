<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Console;

use App\Models\Organization;
use App\Models\RealtimeApp;
use App\Modules\Realtime\Services\CloudflareRealtimeBackend;
use App\Modules\Realtime\Services\RealtimeBackendFactory;
use App\Modules\Realtime\Services\RealtimeCloudflareClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * One-shot setup for the dply-managed Realtime relay on *your* Cloudflare
 * account. It does end-to-end what the README's manual operator steps describe:
 *
 *   1. verify the Cloudflare API token,
 *   2. ensure the APPS KV namespace exists (find or --create-namespace),
 *   3. optionally deploy the realtime Worker (--deploy),
 *   4. provision a control-plane RealtimeApp (writes its creds into KV),
 *   5. optionally publish a live test event (--test),
 *   6. print — and with --write-env, persist — the broadcasting .env block.
 *
 * Use it once for prod, and again locally (with --write-env in a local env) so
 * local dev broadcasts through the same Cloudflare relay customers use instead
 * of the in-box Reverb daemon.
 *
 *   php artisan dply:realtime:setup --create-namespace --deploy --test --write-env
 */
class RealtimeSetupCommand extends Command
{
    protected $signature = 'dply:realtime:setup
                            {--org= : Organization id or slug that owns the control-plane app (default: first org)}
                            {--name=dply control plane : Name for the realtime app}
                            {--host= : Public relay host (default: config realtime.host)}
                            {--create-namespace : Create the APPS KV namespace if none is configured/found}
                            {--deploy : Deploy the realtime Worker via wrangler (needs node + npm)}
                            {--test : Publish a live test event to confirm the relay accepts it}
                            {--write-env : Upsert the resolved PUSHER_* block into the project .env}';

    protected $description = 'Configure the managed Realtime relay on your Cloudflare account end to end.';

    public function handle(): int
    {
        if (RealtimeBackendFactory::fakeEnabled()) {
            $this->error('DPLY_FAKE_REALTIME is enabled — this command configures REAL Cloudflare.');
            $this->line('Set DPLY_FAKE_REALTIME=false (and DPLY_FAKE_EDGE=false), then re-run.');

            return self::FAILURE;
        }

        $organization = $this->resolveOrganization();
        if (! $organization instanceof Organization) {
            return self::FAILURE;
        }

        $client = RealtimeCloudflareClient::fromConfig();

        // 1. Token --------------------------------------------------------
        $tokenActive = false;
        $this->components->task('Verify Cloudflare API token', function () use ($client, &$tokenActive): bool {
            return $tokenActive = $client->tokenIsActive();
        });
        if (! $tokenActive) {
            $this->error('Cloudflare token invalid/inactive. Set DPLY_REALTIME_CF_API_TOKEN (or DPLY_EDGE_CF_API_TOKEN) with Workers KV Storage: Edit.');

            return self::FAILURE;
        }

        // 2. KV namespace -------------------------------------------------
        $namespaceId = $this->resolveNamespace($client);
        if ($namespaceId === null) {
            return self::FAILURE;
        }
        // Point the backend (and any later config read) at the resolved id.
        config(['realtime.cloudflare.kv_namespace_id' => $namespaceId]);

        // 3. Deploy worker (optional) ------------------------------------
        if ($this->option('deploy') && ! $this->deployWorker()) {
            $this->warn('Worker deploy did not complete — continuing; you can deploy manually with `cd packages/realtime-worker && npm run deploy`.');
        }

        // 4. Worker health -----------------------------------------------
        $host = (string) ($this->option('host') ?: config('realtime.host'));
        $this->checkWorkerHealth($host);

        // 5. Provision the control-plane app -----------------------------
        $app = $this->provisionApp($organization, $host);
        if (! $app instanceof RealtimeApp) {
            return self::FAILURE;
        }

        // 6. Live publish test (optional) --------------------------------
        if ($this->option('test')) {
            $this->publishTestEvent($app);
        }

        // 7. Env block ----------------------------------------------------
        $env = $this->buildEnv($app);
        $this->renderEnv($env);

        if ($this->option('write-env')) {
            $this->writeEnv($env);
        }

        $this->newLine();
        $this->info('Realtime relay configured.');
        $this->line('Next: run `php artisan config:cache` on each box (and restart Horizon on workers) so the new broadcast connection is picked up.');

        return self::SUCCESS;
    }

    private function resolveOrganization(): ?Organization
    {
        $ref = trim((string) $this->option('org'));

        if ($ref !== '') {
            $org = Organization::query()->where('id', $ref)->orWhere('slug', $ref)->first();
            if (! $org) {
                $this->error('No organization found for "'.$ref.'".');

                return null;
            }

            return $org;
        }

        $org = Organization::query()->orderBy('created_at')->first();
        if (! $org) {
            $this->error('No organizations exist — create one before setting up realtime.');

            return null;
        }

        $this->line('Using organization: '.$org->name.' ('.$org->id.'). Pass --org to choose another.');

        return $org;
    }

    private function resolveNamespace(RealtimeCloudflareClient $client): ?string
    {
        $title = 'dply-realtime-APPS';
        $configured = (string) config('realtime.cloudflare.kv_namespace_id');

        if ($configured !== '') {
            if ($client->namespaceExists($configured)) {
                $this->components->info('Using configured KV namespace '.$configured.'.');

                return $configured;
            }
            $this->warn('DPLY_REALTIME_CF_KV_NAMESPACE_ID='.$configured.' does not exist in this account.');
        }

        // Reuse an existing namespace by title, or the one pinned in wrangler.toml.
        $existing = $client->listNamespaces();
        if (isset($existing[$title])) {
            $id = $existing[$title];
            $this->components->info('Found existing namespace "'.$title.'" → '.$id.'.');
            $this->reminderSetNamespaceEnv($id);

            return $id;
        }

        $pinned = $this->wranglerNamespaceId();
        if ($pinned !== null && in_array($pinned, array_values($existing), true)) {
            $this->components->info('Using namespace pinned in wrangler.toml → '.$pinned.'.');
            $this->reminderSetNamespaceEnv($pinned);

            return $pinned;
        }

        if (! $this->option('create-namespace')) {
            $this->error('No APPS KV namespace configured or found. Re-run with --create-namespace to create one.');

            return null;
        }

        $id = $client->createNamespace($title);
        $this->components->info('Created KV namespace "'.$title.'" → '.$id.'.');
        $this->updateWranglerNamespaceId($id);
        $this->reminderSetNamespaceEnv($id);

        return $id;
    }

    private function reminderSetNamespaceEnv(string $id): void
    {
        if ((string) config('realtime.cloudflare.kv_namespace_id') !== $id) {
            $this->warn('Set DPLY_REALTIME_CF_KV_NAMESPACE_ID='.$id.' in your .env so future runs/jobs use it.');
        }
    }

    private function wranglerNamespaceId(): ?string
    {
        $path = base_path('packages/realtime-worker/wrangler.toml');
        if (! is_file($path)) {
            return null;
        }

        $toml = (string) file_get_contents($path);
        if (preg_match('/binding\s*=\s*"APPS"\s*\n\s*id\s*=\s*"([^"]+)"/', $toml, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function updateWranglerNamespaceId(string $id): void
    {
        $path = base_path('packages/realtime-worker/wrangler.toml');
        if (! is_file($path)) {
            return;
        }

        $toml = (string) file_get_contents($path);
        $updated = preg_replace(
            '/(binding\s*=\s*"APPS"\s*\n\s*id\s*=\s*")[^"]*(")/',
            '${1}'.$id.'${2}',
            $toml,
            1,
        );

        if (is_string($updated) && $updated !== $toml) {
            file_put_contents($path, $updated);
            $this->line('Updated packages/realtime-worker/wrangler.toml APPS binding → '.$id.'.');
        }
    }

    private function deployWorker(): bool
    {
        $dir = base_path('packages/realtime-worker');
        if (! is_dir($dir)) {
            $this->warn('packages/realtime-worker not found — skipping deploy.');

            return false;
        }

        $env = [
            'CLOUDFLARE_API_TOKEN' => (string) config('realtime.cloudflare.api_token'),
            'CLOUDFLARE_ACCOUNT_ID' => (string) config('realtime.cloudflare.account_id'),
        ];

        if (! is_dir($dir.'/node_modules')) {
            $this->line('Installing worker dependencies (npm install)…');
            $install = Process::path($dir)->env($env)->timeout(600)->run('npm install');
            if (! $install->successful()) {
                $this->error('npm install failed: '.trim($install->errorOutput() ?: $install->output()));

                return false;
            }
        }

        $this->line('Deploying worker (npm run deploy)…');
        $deploy = Process::path($dir)->env($env)->timeout(600)
            ->run('npm run deploy', function (string $type, string $buffer): void {
                $this->output->write($buffer);
            });

        return $deploy->successful();
    }

    private function checkWorkerHealth(string $host): void
    {
        $this->components->task('Worker reachable at https://'.$host.'/health', function () use ($host): bool {
            try {
                $response = Http::timeout(8)->acceptJson()->get('https://'.$host.'/health');

                return $response->successful() && (string) $response->json('service') === 'dply-realtime';
            } catch (Throwable) {
                return false;
            }
        });
    }

    private function provisionApp(Organization $organization, string $host): ?RealtimeApp
    {
        $name = trim((string) $this->option('name')) ?: 'dply control plane';

        /** @var RealtimeApp|null $app */
        $app = $organization->realtimeApps()->where('name', $name)->first();

        if (! $app) {
            $credentials = RealtimeApp::generateCredentials();
            $tier = (string) config('realtime.default_tier', 'starter');
            $app = $organization->realtimeApps()->create([
                'name' => $name,
                'app_key' => $credentials['app_key'],
                'app_secret' => $credentials['app_secret'],
                'status' => RealtimeApp::STATUS_PROVISIONING,
                'backend' => 'dply_realtime',
                'tier' => $tier,
                'host' => $host,
                'max_connections' => (int) config("realtime.tiers.{$tier}.max_connections", config('realtime.plan.max_connections')),
            ]);
            $this->line('Created realtime app "'.$name.'" ('.$app->id.').');
        } else {
            $app->forceFill(['host' => $host])->save();
            $this->line('Reusing realtime app "'.$name.'" ('.$app->id.') — re-syncing its KV record.');
        }

        // Mark active BEFORE provisioning: the KV record's `enabled` flag is
        // derived from status (RealtimeApp::kvRecord), and the relay rejects
        // publishes/connects for a disabled app with `invalid_app`.
        $app->forceFill(['status' => RealtimeApp::STATUS_ACTIVE, 'error_message' => null])->save();

        try {
            // Build the backend fresh so it reads the namespace id we just resolved.
            CloudflareRealtimeBackend::fromConfig()->provision($app->fresh());
        } catch (Throwable $e) {
            $app->forceFill(['status' => RealtimeApp::STATUS_FAILED, 'error_message' => $e->getMessage()])->save();
            $this->error('Failed to write credentials to KV: '.$e->getMessage());

            return null;
        }

        $this->components->info('Provisioned credentials into KV for app '.$app->id.'.');

        return $app;
    }

    private function publishTestEvent(RealtimeApp $app): void
    {
        $this->components->task('Publish a live test event', function () use ($app): bool {
            try {
                $response = Http::withHeaders($app->statsAuthHeaders())
                    ->acceptJson()
                    ->post($app->publishEndpoint(), [
                        'name' => 'dply.realtime.setup.test',
                        'channels' => ['dply-setup-probe'],
                        'data' => ['ok' => true],
                    ]);

                return $response->successful();
            } catch (Throwable) {
                return false;
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private function buildEnv(RealtimeApp $app): array
    {
        $base = [
            'BROADCAST_CONNECTION' => 'pusher',
            'PUSHER_APP_ID' => (string) $app->id,
            'PUSHER_APP_KEY' => (string) $app->app_key,
            'PUSHER_APP_SECRET' => (string) $app->app_secret,
            'PUSHER_HOST' => $app->host(),
            'PUSHER_PORT' => '443',
            'PUSHER_SCHEME' => 'https',
            'PUSHER_APP_CLUSTER' => 'mt1',
        ];

        // Browser Echo mirrors — secret is server-only, never mirrored.
        $vite = [];
        foreach ($base as $key => $value) {
            if ($key === 'PUSHER_APP_SECRET' || ! str_starts_with($key, 'PUSHER_')) {
                continue;
            }
            $vite['VITE_'.$key] = $value;
        }

        return [...$base, ...$vite];
    }

    /**
     * @param  array<string, string>  $env
     */
    private function renderEnv(array $env): void
    {
        $this->newLine();
        $this->line('<fg=cyan>Broadcasting .env (web + every worker box; secret-bearing — store securely):</>');
        $this->newLine();
        foreach ($env as $key => $value) {
            $this->line($key.'='.$value);
        }
    }

    /**
     * @param  array<string, string>  $env
     */
    private function writeEnv(array $env): void
    {
        $path = base_path('.env');
        if (! is_file($path)) {
            $this->warn('.env not found at '.$path.' — skipping --write-env.');

            return;
        }

        $contents = (string) file_get_contents($path);

        foreach ($env as $key => $value) {
            $line = $key.'='.$this->quoteEnvValue($value);
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents) === 1) {
                $contents = (string) preg_replace($pattern, $line, $contents, 1);
            } else {
                $contents = rtrim($contents, "\n")."\n".$line."\n";
            }
        }

        file_put_contents($path, $contents);
        $this->components->info('Wrote broadcasting keys into '.$path.'. Run `php artisan config:clear` (local) or `config:cache` (prod).');
    }

    private function quoteEnvValue(string $value): string
    {
        return preg_match('/\s|#|"/', $value) === 1
            ? '"'.str_replace('"', '\\"', $value).'"'
            : $value;
    }
}
