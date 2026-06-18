<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerCronJob;
use App\Models\Site;
use App\Services\Servers\ServerCronJobRunner;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesCronCommandPresets
{
    public string $frequency_preset = 'every_minute';

    public string $command_preset = 'custom';

    /**
     * Whether this site can use Dply-managed SSH crontab entries on the VM.
     */
    protected function siteSupportsVmManagedCron(Site $site): bool
    {
        return $this->server->hostCapabilities()->supportsSsh()
            && ! $site->usesFunctionsRuntime()
            && ! $site->usesDockerRuntime()
            && ! $site->usesKubernetesRuntime();
    }

    public function fillLaravelSchedulerCommand(): void
    {
        $this->authorize('update', $this->server);

        $siteId = $this->new_site_id ?: $this->context_site_id;
        if ($siteId === null || $siteId === '') {
            return;
        }

        $site = Site::query()->where('server_id', $this->server->id)->whereKey($siteId)->first();
        if ($site === null || ! $site->isLaravelFrameworkDetected()) {
            return;
        }

        $artisan = rtrim($site->effectiveRepositoryPath(), '/').'/current/artisan';
        $this->new_cron_command = 'php '.escapeshellarg($artisan).' schedule:run';
        $this->new_cron_user = $site->effectiveSystemUser($this->server);
    }

    /**
     * Sets the Command field from a common-command preset (mostly Laravel artisan).
     * Pure form-fill: it only touches {@see $new_cron_command} so the user can keep editing.
     */
    public function applyArtisanCommandPreset(string $key): void
    {
        $this->authorize('update', $this->server);

        // Presets are grouped ($group => [['key'=>…, 'command'=>…], …]), so find
        // the entry by its key across every group.
        foreach ($this->artisanCommandPresets() as $items) {
            foreach ($items as $item) {
                if (($item['key'] ?? null) === $key) {
                    $this->new_cron_command = $item['command'];

                    return;
                }
            }
        }
    }

    /**
     * Prefix that runs an artisan command for the in-context site, fully resolved when we
     * know the site path + PHP version (e.g. `php8.3 /home/dply/app/current/artisan`), or a
     * clear editable template (`php /home/dply/<site>/artisan`) when no site is selected.
     */
    protected function artisanInvocationPrefix(): string
    {
        $site = $this->schedulerHelperTargetSite();

        if ($site !== null && $site->isLaravelFrameworkDetected()) {
            $version = $site->phpVersion();
            $php = ($version !== null && $version !== '') ? 'php'.$version : 'php';
            $artisan = rtrim($site->effectiveRepositoryPath(), '/').'/current/artisan';

            return $php.' '.escapeshellarg($artisan);
        }

        return 'php /home/dply/<site>/current/artisan';
    }

    /**
     * Common commands offered under the Command field. Primarily Laravel artisan tasks
     * grouped into scheduler / queues / maintenance, plus a couple of generic entries.
     * Each command is the full string written into {@see $new_cron_command}; artisan
     * entries are prefixed by {@see artisanInvocationPrefix()} (resolved path + PHP binary
     * when a Laravel site is in context, otherwise an editable template).
     *
     * @return array<string, array<int, array{key: string, label: string, command: string}>>
     */
    protected function artisanCommandPresets(): array
    {
        $artisan = $this->artisanInvocationPrefix();

        $make = fn (string $key, string $label, string $args): array => [
            'key' => $key,
            'label' => $label,
            'command' => $artisan.' '.$args,
        ];

        return [
            __('Laravel scheduler') => [
                $make('schedule_run', __('schedule:run (per-minute scheduler)'), 'schedule:run >> /dev/null 2>&1'),
            ],
            __('Queues') => [
                $make('queue_work', __('queue:work --stop-when-empty'), 'queue:work --stop-when-empty'),
                $make('queue_restart', __('queue:restart'), 'queue:restart'),
                $make('horizon_snapshot', __('horizon:snapshot'), 'horizon:snapshot'),
            ],
            __('Maintenance') => [
                $make('backup_run', __('backup:run'), 'backup:run'),
                $make('model_prune', __('model:prune'), 'model:prune'),
                $make('cache_prune_tags', __('cache:prune-stale-tags'), 'cache:prune-stale-tags'),
                $make('sanctum_prune', __('sanctum:prune-expired'), 'sanctum:prune-expired --hours=24'),
                $make('telescope_prune', __('telescope:prune'), 'telescope:prune --hours=48'),
            ],
            __('Generic') => [
                [
                    'key' => 'curl_health',
                    'label' => __('curl health check (HTTP ping)'),
                    'command' => 'curl -fsS -o /dev/null https://example.com/health',
                ],
                [
                    'key' => 'shell_script',
                    'label' => __('run a shell script'),
                    'command' => '/home/dply/<site>/current/scripts/task.sh',
                ],
            ],
        ];
    }

    public function updatedFrequencyPreset(string $value): void
    {
        if ($value !== 'custom' && isset($this->presetExpressions[$value])) {
            $this->new_cron_expression = $this->presetExpressions[$value];
        }
    }

    public function updatedCommandPreset(string $value): void
    {
        if ($value === 'custom') {
            return;
        }

        $def = $this->commandInstallPresets()[$value] ?? null;
        if ($def === null) {
            return;
        }

        $this->new_cron_expression = $def['cron_expression'];
        $this->new_cron_command = $def['command'];
        $this->new_description = $def['description'] ?? null;
        if (array_key_exists('user', $def) && $def['user'] !== null) {
            $this->new_cron_user = $def['user'];
        }
        $this->updatedNewCronExpression();
    }

    /**
     * Common cron entries people add after installing a stack or package (edit paths/domains before saving).
     *
     * @return array<string, array{label: string, cron_expression: string, command: string, description?: string|null, user?: string|null}>
     */
    protected function commandInstallPresets(): array
    {
        $sshUser = trim((string) $this->server->ssh_user);
        $laravelHomeUser = ($sshUser === '' || $sshUser === 'root') ? 'deploy' : $sshUser;

        $presets = [
            'laravel_schedule' => [
                'label' => __('Laravel — `schedule:run`'),
                'cron_expression' => '* * * * *',
                'command' => 'cd /home/'.$laravelHomeUser.'/your-app/current && php artisan schedule:run >> /dev/null 2>&1',
                'description' => 'Laravel scheduler',
            ],
            'certbot_renew' => [
                'label' => __("Certbot — Let's Encrypt renew"),
                'cron_expression' => '0 3 * * *',
                'command' => 'certbot renew --quiet --deploy-hook "systemctl reload nginx"',
                'description' => "Let's Encrypt renewal",
                'user' => 'root',
            ],
            'wordpress_wp_cron' => [
                'label' => __('WordPress — wp-cron via HTTP'),
                'cron_expression' => '*/15 * * * *',
                'command' => 'curl -fsS -o /dev/null "https://example.com/wp-cron.php?doing_wp_cron"',
                'description' => 'WordPress wp-cron (HTTP)',
            ],
            'logrotate_nginx' => [
                'label' => __('Nginx — logrotate'),
                'cron_expression' => '0 0 * * *',
                'command' => '/usr/sbin/logrotate -f /etc/logrotate.d/nginx 2>&1 | logger -t nginx-logrotate',
                'description' => 'Nginx log rotation',
                'user' => 'root',
            ],
            'apt_auto_upgrade' => [
                'label' => __('Debian/Ubuntu — weekly apt upgrade'),
                'cron_expression' => '0 6 * * 0',
                'command' => 'export DEBIAN_FRONTEND=noninteractive; apt-get update -qq && apt-get -y -qq upgrade',
                'description' => 'Weekly apt upgrade',
                'user' => 'root',
            ],
        ];

        $site = $this->schedulerHelperTargetSite();
        if ($site === null || ! $site->isLaravelFrameworkDetected()) {
            unset($presets['laravel_schedule']);
        }

        return $presets;
    }

    public function dryRunFormCommand(ServerCronJobRunner $runner): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_cron_command' => 'required|string|max:2000',
            'new_cron_user' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'new_overlap_policy' => ['required', 'string', Rule::in([ServerCronJob::OVERLAP_ALLOW, ServerCronJob::OVERLAP_SKIP_IF_RUNNING])],
            'new_env_prefix' => ['nullable', 'string', 'max:4000'],
            'new_schedule_timezone' => ['nullable', 'string', 'max:64'],
        ]);
        $temp = new ServerCronJob([
            'command' => trim($this->new_cron_command),
            'user' => trim($this->new_cron_user),
            'overlap_policy' => $this->new_overlap_policy,
            'env_prefix' => trim((string) ($this->new_env_prefix ?? '')) ?: null,
            'schedule_timezone' => trim((string) ($this->new_schedule_timezone ?? '')) ?: null,
        ]);
        $temp->id = $this->editing_job_id ?? (string) Str::ulid();
        $temp->setRelation('server', $this->server->fresh());
        try {
            $text = $runner->dryRunPreview($this->server->fresh(), $temp);
            $this->toastSuccess($text);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    protected function schedulerHelperTargetSite(): ?Site
    {
        $sid = $this->new_site_id ?: $this->context_site_id;
        if ($sid === null || $sid === '') {
            return null;
        }

        return Site::query()->where('server_id', $this->server->id)->whereKey($sid)->first();
    }
}
