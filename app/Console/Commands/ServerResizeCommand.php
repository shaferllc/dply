<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ResizeServerJob;
use App\Models\Server;
use App\Modules\Notifications\Services\ServerResizeNotificationDispatcher;
use App\Services\Servers\ServerResizeOptions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Resize a server from the CLI — and, crucially, diagnose why the UI button
 * appeared to do nothing.
 *
 *   dply:server:resize divineiv                    # list legal targets
 *   dply:server:resize divineiv s-1vcpu-2gb --sync # run it HERE, no queue
 *   dply:server:resize divineiv s-1vcpu-2gb        # queue it, like the button
 *   dply:server:resize divineiv --unlock           # clear a stuck unique lock
 *
 * `--sync` is the important one. {@see ResizeServerJob} is queued, so when the
 * button "does nothing" the cause is almost never the resize logic — it is the
 * worker: it cannot autoload the class, or it is not running, or the job is
 * being silently dropped by its own uniqueness lock. Running inline removes
 * the queue from the picture and surfaces the real exception on stdout.
 *
 * The lock deserves its own mention. ResizeServerJob is ShouldBeUnique, so a
 * dispatch while a lock is held is discarded WITHOUT error — no exception, no
 * failed job, no log line. A worker killed mid-resize before $uniqueFor was
 * introduced left that lock with no expiry at all, which silently swallows
 * every later resize of that server. This command reports the lock's state
 * before doing anything, and --unlock clears just that one key rather than
 * flushing the whole cache.
 */
class ServerResizeCommand extends Command
{
    protected $signature = 'dply:server:resize
        {server : Server ID, name, provider ID, or IP}
        {size? : Target size slug (omit to list legal targets)}
        {--sync : Run the resize inline instead of queueing it}
        {--unlock : Release a stuck uniqueness lock for this server, then exit}';

    protected $description = 'Resize a server, list its legal targets, or clear a stuck resize lock.';

    public function handle(ServerResizeOptions $options, ServerResizeNotificationDispatcher $notifier): int
    {
        $server = Server::query()
            ->where('id', $this->argument('server'))
            ->orWhere('name', $this->argument('server'))
            ->orWhere('provider_id', $this->argument('server'))
            ->orWhere('ip_address', $this->argument('server'))
            ->first();

        if ($server === null) {
            $this->components->error('Server not found: '.$this->argument('server'));

            return self::FAILURE;
        }

        $lockKey = 'laravel_unique_job:'.ResizeServerJob::class.':'.$server->id;

        if ($this->option('unlock')) {
            Cache::forget($lockKey);
            $this->components->info('Released '.$lockKey);

            return self::SUCCESS;
        }

        if (! $options->supports($server)) {
            $this->components->error(sprintf(
                'dply cannot resize a %s server. Supported: DigitalOcean, Hetzner, Vultr, AWS EC2.',
                $server->provider->value,
            ));

            return self::FAILURE;
        }

        // Report the lock before anything else — a held lock is the one failure
        // mode that produces no error of its own.
        if (Cache::has($lockKey)) {
            $this->components->warn(
                'A uniqueness lock is currently held for this server. A queued resize would be '
                .'SILENTLY DROPPED. Clear it with --unlock (or wait for it to expire).'
            );
        }

        $size = (string) ($this->argument('size') ?? '');

        if ($size === '') {
            return $this->listTargets($options, $server);
        }

        try {
            $target = $options->resolveTarget($server, $size);
        } catch (\Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('server', (string) $server->name);
        $this->components->twoColumnDetail('from', (string) ($server->size ?: '?'));
        $this->components->twoColumnDetail('to', (string) $target['slug']);
        $this->components->twoColumnDetail('grows disk', $target['grows_disk'] ? 'YES — permanent' : 'no');

        if (! $this->option('sync')) {
            ResizeServerJob::dispatch($server, $target['slug'], (bool) $target['grows_disk']);
            $this->components->info('Queued. Watch it with: php artisan dply:server:size '.$server->name);

            return self::SUCCESS;
        }

        $this->components->warn('Running inline — this blocks until the provider finishes, and powers the machine off.');

        // Straight to handle(): bypasses the queue AND the uniqueness lock, so
        // this still works on a box whose workers are broken.
        (new ResizeServerJob($server, $target['slug'], (bool) $target['grows_disk']))
            ->handle($options, $notifier);

        $state = data_get($server->fresh()?->meta, 'resize.state');
        if ($state === 'completed') {
            $this->components->info('Resize completed.');

            return self::SUCCESS;
        }

        $this->components->error('Resize ended in state: '.((string) ($state ?? 'unknown')));
        $this->components->info((string) (data_get($server->fresh()?->meta, 'resize.error') ?? ''));

        return self::FAILURE;
    }

    private function listTargets(ServerResizeOptions $options, Server $server): int
    {
        try {
            $catalog = $options->forServer($server);
        } catch (\Throwable $e) {
            $this->components->error('Provider lookup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $current = $catalog['current'];
        $this->components->info(sprintf(
            '%s is currently %s (%s vCPU, %s MB, %s GB disk) in %s',
            $server->name,
            $current['slug'] ?? '?',
            $current['vcpus'] ?? '?',
            $current['memory_mb'] ?? '?',
            $current['disk_gb'] ?? '?',
            $current['region'] ?? '?',
        ));

        if ($catalog['options'] === []) {
            $this->components->warn('No legal resize targets for this machine in its region.');

            return self::SUCCESS;
        }

        $this->table(
            ['size', 'vCPU', 'memory', 'disk', '$/mo', 'disk grows?'],
            array_map(fn (array $o): array => [
                $o['slug'],
                $o['vcpus'],
                $o['memory_mb'].' MB',
                $o['disk_gb'] === null ? '—' : $o['disk_gb'].' GB',
                $o['price_monthly'] === null ? '—' : (string) $o['price_monthly'],
                $o['grows_disk'] ? 'YES (permanent)' : 'no',
            ], $catalog['options']),
        );

        return self::SUCCESS;
    }
}
