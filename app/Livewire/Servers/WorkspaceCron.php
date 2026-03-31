<?php

namespace App\Livewire\Servers;

use App\Jobs\RunServerCronJobNowJob;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\OrganizationCronJobTemplate;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerCronJobRun;
use App\Services\Servers\CronExpressionValidator;
use App\Services\Servers\ServerCronJobRunner;
use App\Services\Servers\ServerCronSynchronizer;
use App\Services\Servers\ServerCrontabReader;
use App\Services\Servers\ServerPasswdUserLister;
use App\Services\Servers\ServerRemovalAdvisor;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceCron extends Component
{
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;

    public ?string $editing_job_id = null;

    public string $frequency_preset = 'every_minute';

    public string $command_preset = 'custom';

    public string $new_cron_expression = '* * * * *';

    public string $new_cron_command = '';

    public string $new_cron_user = '';

    public ?string $new_description = null;

    public ?string $new_site_id = null;

    public ?string $new_schedule_timezone = null;

    public string $new_overlap_policy = ServerCronJob::OVERLAP_ALLOW;

    public bool $new_alert_on_failure = false;

    public bool $new_alert_on_pattern_match = false;

    public ?string $new_alert_pattern = null;

    public ?string $new_env_prefix = null;

    public ?string $new_depends_on_job_id = null;

    public ?string $new_maintenance_tag = null;

    public string $cron_job_search = '';

    public ?string $org_maintenance_until_local = null;

    public string $org_maintenance_note = '';

    public ?string $template_save_name = null;

    /** @var array<string, string> */
    protected array $presetExpressions = [
        'every_minute' => '* * * * *',
        'hourly' => '0 * * * *',
        'nightly' => '0 2 * * *',
        'weekly' => '0 2 * * 0',
        'monthly' => '0 2 1 * *',
        'custom' => '* * * * *',
    ];

    public ?string $viewing_logs_job_id = null;

    /** @var 'jobs'|'run'|'inspect'|'history'|'templates' */
    public string $cron_workspace_tab = 'jobs';

    public string $inspect_crontab_user = '';

    public ?string $inspect_crontab_body = null;

    public ?int $inspect_crontab_exit_code = null;

    public ?string $cron_run_id = null;

    public string $cron_run_meta_html = '';

    public string $cron_run_output = '';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        $this->new_cron_user = trim((string) $server->ssh_user) ?: 'root';
        $this->inspect_crontab_user = $this->new_cron_user;
        $this->new_schedule_timezone = config('app.timezone');
        $server->loadMissing('organization');
        $org = $server->organization;
        if ($org?->cron_maintenance_until) {
            $this->org_maintenance_until_local = $org->cron_maintenance_until->timezone(config('app.timezone'))->format('Y-m-d\TH:i');
        }
        $this->org_maintenance_note = (string) ($org?->cron_maintenance_note ?? '');
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

        return [
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
    }

    public function updatedNewCronExpression(): void
    {
        $expr = trim($this->new_cron_expression);
        foreach ($this->presetExpressions as $key => $preset) {
            if ($key !== 'custom' && $preset === $expr) {
                $this->frequency_preset = $key;

                return;
            }
        }
        $this->frequency_preset = 'custom';
    }

    public function startEdit(string $jobId): void
    {
        $this->authorize('update', $this->server);
        $job = ServerCronJob::query()->where('server_id', $this->server->id)->findOrFail($jobId);
        $this->editing_job_id = $job->id;
        $this->new_cron_expression = $job->cron_expression;
        $this->new_cron_command = $job->command;
        $this->new_cron_user = $job->user;
        $this->new_description = $job->description;
        $this->new_site_id = $job->site_id;
        $this->command_preset = 'custom';
        $this->new_schedule_timezone = $job->schedule_timezone ?? config('app.timezone');
        $this->new_overlap_policy = $job->overlap_policy ?? ServerCronJob::OVERLAP_ALLOW;
        $this->new_alert_on_failure = (bool) $job->alert_on_failure;
        $this->new_alert_on_pattern_match = (bool) $job->alert_on_pattern_match;
        $this->new_alert_pattern = $job->alert_pattern;
        $this->new_env_prefix = $job->env_prefix;
        $this->new_depends_on_job_id = $job->depends_on_job_id;
        $this->new_maintenance_tag = $job->maintenance_tag;
        $this->updatedNewCronExpression();
        $this->cron_workspace_tab = 'jobs';
    }

    public function cancelEdit(): void
    {
        $this->editing_job_id = null;
        $this->resetForm();
    }

    public function saveCronJob(CronExpressionValidator $cronValidator): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'new_cron_expression' => ['required', 'string', 'max:64', 'regex:/^(\S+\s+){4}\S+$/'],
            'new_cron_command' => 'required|string|max:2000',
            'new_cron_user' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'new_description' => 'nullable|string|max:500',
            'new_site_id' => ['nullable', 'ulid', Rule::exists('sites', 'id')->where('server_id', $this->server->id)],
            'new_schedule_timezone' => ['nullable', 'string', 'max:64'],
            'new_overlap_policy' => ['required', 'string', Rule::in([ServerCronJob::OVERLAP_ALLOW, ServerCronJob::OVERLAP_SKIP_IF_RUNNING])],
            'new_alert_pattern' => ['nullable', 'string', 'max:512'],
            'new_env_prefix' => ['nullable', 'string', 'max:4000'],
            'new_depends_on_job_id' => ['nullable', 'ulid', Rule::exists('server_cron_jobs', 'id')->where('server_id', $this->server->id)],
            'new_maintenance_tag' => ['nullable', 'string', 'max:64'],
        ], [
            'new_cron_expression.regex' => __('Use five cron fields (minute hour day month weekday).'),
            'new_cron_user.regex' => __('Use a valid Linux username.'),
        ]);

        if (! $cronValidator->isValid(trim($this->new_cron_expression))) {
            $this->addError('new_cron_expression', __('This cron expression is not valid.'));

            return;
        }

        if ($this->new_alert_pattern !== null && trim($this->new_alert_pattern) !== '') {
            set_error_handler(static fn () => true);
            $ok = @preg_match($this->new_alert_pattern, '') !== false;
            restore_error_handler();
            if (! $ok) {
                $this->addError('new_alert_pattern', __('Enter a valid PCRE pattern (include delimiters), e.g. /error/i'));

                return;
            }
        }

        if ($this->editing_job_id && $this->new_depends_on_job_id === $this->editing_job_id) {
            $this->addError('new_depends_on_job_id', __('A job cannot depend on itself.'));

            return;
        }

        $payload = [
            'cron_expression' => trim($this->new_cron_expression),
            'command' => trim($this->new_cron_command),
            'user' => trim($this->new_cron_user),
            'description' => trim((string) $this->new_description) ?: null,
            'site_id' => $this->new_site_id ?: null,
            'is_synced' => false,
            'schedule_timezone' => trim((string) ($this->new_schedule_timezone ?? '')) ?: null,
            'overlap_policy' => $this->new_overlap_policy,
            'alert_on_failure' => $this->new_alert_on_failure,
            'alert_on_pattern_match' => $this->new_alert_on_pattern_match,
            'alert_pattern' => trim((string) ($this->new_alert_pattern ?? '')) ?: null,
            'env_prefix' => trim((string) ($this->new_env_prefix ?? '')) ?: null,
            'depends_on_job_id' => $this->new_depends_on_job_id ?: null,
            'maintenance_tag' => trim((string) ($this->new_maintenance_tag ?? '')) ?: null,
        ];

        if ($this->editing_job_id) {
            ServerCronJob::query()
                ->where('server_id', $this->server->id)
                ->whereKey($this->editing_job_id)
                ->firstOrFail()
                ->update($payload);
            $this->flash_success = __('Cron job updated. Sync crontab on the server to apply changes.');
        } else {
            ServerCronJob::query()->create(array_merge($payload, [
                'server_id' => $this->server->id,
                'enabled' => true,
            ]));
            $this->flash_success = __('Cron job added. Sync crontab on the server to install the Dply-managed block.');
        }

        $this->flash_error = null;
        $this->cancelEdit();
    }

    public function toggleCronJob(string $jobId): void
    {
        $this->authorize('update', $this->server);
        $job = ServerCronJob::query()->where('server_id', $this->server->id)->findOrFail($jobId);
        $job->update(['enabled' => ! $job->enabled, 'is_synced' => false]);
        $this->flash_success = $job->enabled
            ? __('Job enabled. Sync crontab to apply.')
            : __('Job paused (omitted from crontab on next sync).');
        $this->flash_error = null;
    }

    public function deleteCronJob(string $jobId): void
    {
        $this->authorize('update', $this->server);
        ServerCronJob::query()->where('server_id', $this->server->id)->whereKey($jobId)->firstOrFail()->delete();
        if ($this->editing_job_id === $jobId) {
            $this->cancelEdit();
        }
        $this->flash_success = __('Cron entry removed. Sync crontab again to update the server.');
        $this->flash_error = null;
    }

    public function runCronJobNow(string $jobId): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;

        $this->cron_workspace_tab = 'run';

        $job = ServerCronJob::query()->where('server_id', $this->server->id)->findOrFail($jobId);
        if (! $job->enabled) {
            $this->flash_error = __('Enable this job before running it.');

            return;
        }

        $this->server->loadMissing('organization');
        $org = $this->server->organization;
        if ($org?->cron_maintenance_until && now()->lt($org->cron_maintenance_until)) {
            $this->flash_error = __('Cron runs are paused for this organization until the maintenance window ends. Clear it under the Maintenance tab or ask an admin.');

            return;
        }

        $runId = (string) Str::ulid();
        $this->cron_run_id = $runId;
        $this->cron_run_meta_html = '';
        $this->cron_run_output = '';

        RunServerCronJobNowJob::dispatch($this->server->id, $job->id, $runId);

        $rid = json_encode($runId);
        // Set active run id for Echo (may already be set from the first broadcast if the worker was fast).
        $this->js('window.__dplyCronRunActiveId='.$rid.';');

        $this->flash_success = __('Run queued. A queue worker runs it over SSH; output appears here via Reverb or polling.');
    }

    /**
     * Poll fallback when Echo/Reverb is unavailable (same cache payload as the queued job).
     * While status is running, do not shrink {@see $cron_run_output} below cache length so Reverb
     * chunk events are not overwritten by a slightly stale poll.
     */
    public function syncCronRunFromCache(): void
    {
        if ($this->cron_run_id === null || $this->cron_run_id === '') {
            return;
        }

        $payload = Cache::get(RunServerCronJobNowJob::cacheKey($this->cron_run_id));
        if (! is_array($payload)) {
            return;
        }

        $this->cron_run_meta_html = (string) ($payload['meta_html'] ?? '');
        $cachedOut = (string) ($payload['output'] ?? '');
        $status = (string) ($payload['status'] ?? '');

        if (in_array($status, ['finished', 'failed'], true)) {
            $this->cron_run_output = $cachedOut;
        } elseif (strlen($cachedOut) >= strlen($this->cron_run_output)) {
            $this->cron_run_output = $cachedOut;
        }

        if (! in_array($status, ['finished', 'failed'], true)) {
            return;
        }

        $this->cron_run_id = null;
        $this->flash_error = null;
        $this->flash_success = null;
        if ($status === 'finished') {
            $this->flash_success = (string) ($payload['flash_success'] ?? __('Finished.'));
        } else {
            $this->flash_error = (string) ($payload['error'] ?? __('Run failed.'));
        }
    }

    #[On('cron-run-meta')]
    public function onCronRunMeta(string $runId, string $metaHtml): void
    {
        if ($runId === '') {
            return;
        }
        if ($this->cron_run_id !== null && $this->cron_run_id !== '' && $runId !== $this->cron_run_id) {
            return;
        }

        $this->cron_run_meta_html = $metaHtml;
        $this->cron_run_output = '';
    }

    #[On('cron-run-chunk')]
    public function onCronRunChunk(string $runId, string $chunk): void
    {
        if ($runId === '' || $chunk === '') {
            return;
        }
        if ($this->cron_run_id !== $runId) {
            return;
        }

        $this->cron_run_output .= $chunk;
    }

    #[On('cron-run-finished')]
    public function onCronRunFinished(mixed ...$payload): void
    {
        $runId = '';
        $success = false;
        $flashSuccess = null;
        $error = null;

        $first = $payload[0] ?? null;
        if (is_array($first)) {
            $runId = (string) ($first['runId'] ?? $first['run_id'] ?? '');
            $success = (bool) ($first['success'] ?? false);
            $flashSuccess = $first['flashSuccess'] ?? $first['flash_success'] ?? null;
            $error = isset($first['error']) ? (is_string($first['error']) ? $first['error'] : null) : null;
        } elseif (count($payload) >= 2) {
            $runId = (string) $payload[0];
            $success = (bool) $payload[1];
            $flashSuccess = isset($payload[2]) && is_string($payload[2]) ? $payload[2] : null;
            $error = isset($payload[3]) && is_string($payload[3]) ? $payload[3] : null;
        }

        if ($runId === '' || $this->cron_run_id !== $runId) {
            return;
        }

        $cached = Cache::get(RunServerCronJobNowJob::cacheKey($runId));
        if (is_array($cached)) {
            $this->cron_run_meta_html = (string) ($cached['meta_html'] ?? $this->cron_run_meta_html);
            $this->cron_run_output = (string) ($cached['output'] ?? $this->cron_run_output);
        }

        $this->cron_run_id = null;
        $this->flash_error = null;
        $this->flash_success = null;
        if ($success) {
            $this->flash_success = is_string($flashSuccess) && $flashSuccess !== '' ? $flashSuccess : __('Finished.');
        } else {
            $this->flash_error = is_string($error) && $error !== '' ? $error : __('Run failed.');
        }
    }

    /**
     * Whether Echo may subscribe for live cron run chunks (Reverb + channel policy).
     */
    public function cronRunEchoSubscribable(): bool
    {
        $user = auth()->user();
        if ($user === null || ! $user->can('view', $this->server)) {
            return false;
        }

        $this->server->loadMissing('organization');

        if ($this->server->organization_id && $this->server->organization?->userIsDeployer($user)) {
            return false;
        }

        if (config('broadcasting.default') === 'null') {
            return false;
        }

        if (! config('broadcasting.echo_client_enabled', true)) {
            return false;
        }

        return filled(config('broadcasting.connections.reverb.key'));
    }

    /**
     * Run-as field: merge /etc/passwd names (cached) with SSH user, root, and job run-as users.
     *
     * @return list<string>
     */
    protected function runAsUserDatalistChoices(): array
    {
        $local = $this->crontabInspectUserChoices();

        if (! $this->server->isReady() || empty($this->server->ssh_private_key)) {
            return $local;
        }

        try {
            $remote = Cache::remember(
                'server_passwd_usernames:'.$this->server->id,
                now()->addMinutes(5),
                fn () => app(ServerPasswdUserLister::class)->listUsernames($this->server->fresh())
            );
        } catch (\Throwable) {
            $remote = [];
        }

        return collect($local)
            ->merge($remote)
            ->map(fn ($u) => trim((string) $u))
            ->filter(fn ($u) => $u !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $u))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function refreshRunAsUserChoices(): void
    {
        $this->authorize('update', $this->server);
        Cache::forget('server_passwd_usernames:'.$this->server->id);
        $this->flash_success = __('Reloaded user names from /etc/passwd on the server.');
        $this->flash_error = null;
    }

    /**
     * Suggested usernames for the crontab inspector (SSH account, root, and run-as users from jobs).
     *
     * @return list<string>
     */
    protected function crontabInspectUserChoices(): array
    {
        $this->server->loadMissing('cronJobs');
        $ssh = trim((string) $this->server->ssh_user) ?: 'root';

        return collect([$ssh, 'root'])
            ->merge($this->server->cronJobs->pluck('user'))
            ->map(fn ($u) => trim((string) $u))
            ->filter(fn ($u) => $u !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $u))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    public function loadInspectCrontab(ServerCrontabReader $reader): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        $this->cron_workspace_tab = 'inspect';
        $this->inspect_crontab_body = null;
        $this->inspect_crontab_exit_code = null;

        $this->validate([
            'inspect_crontab_user' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
        ], [
            'inspect_crontab_user.regex' => __('Use a valid Linux username.'),
        ]);

        try {
            $this->server->refresh();
            $result = $reader->readForUser($this->server->fresh(), trim($this->inspect_crontab_user));
            $this->inspect_crontab_body = $result['body'];
            $this->inspect_crontab_exit_code = $result['exit_code'];

            $noCrontabYet = $result['exit_code'] === 1
                && $result['body'] !== ''
                && str_contains(strtolower($result['body']), 'no crontab');

            if ($result['exit_code'] !== null && $result['exit_code'] !== 0 && ! $noCrontabYet) {
                $this->flash_error = __('Could not read crontab (exit :code). Output is shown below.', ['code' => $result['exit_code']]);
            } else {
                $this->flash_error = null;
            }
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function syncCronJobs(ServerCronSynchronizer $synchronizer): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $this->server->refresh();
            $out = $synchronizer->sync($this->server);
            $this->flash_success = __('Crontab sync finished. Output: :out', ['out' => Str::limit(trim($out), 800)]);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function validateCronExpressionField(CronExpressionValidator $cronValidator): void
    {
        $this->authorize('update', $this->server);
        $this->resetErrorBag('new_cron_expression');
        $expr = trim($this->new_cron_expression);
        if ($cronValidator->isValid($expr)) {
            $this->flash_success = __('Cron expression looks valid.');
            $this->flash_error = null;
        } else {
            $this->flash_error = __('That cron expression is not valid.');
            $this->flash_success = null;
        }
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
            $this->flash_success = $text;
            $this->flash_error = null;
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
            $this->flash_success = null;
        }
    }

    public function saveOrgCronTemplate(): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org === null) {
            return;
        }
        $this->authorize('update', $org);

        $this->validate([
            'template_save_name' => ['required', 'string', 'max:120'],
            'new_cron_expression' => ['required', 'string', 'max:64'],
            'new_cron_command' => 'required|string|max:2000',
            'new_cron_user' => ['required', 'string', 'max:64'],
        ]);

        OrganizationCronJobTemplate::query()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'name' => trim($this->template_save_name),
            ],
            [
                'cron_expression' => trim($this->new_cron_expression),
                'command' => trim($this->new_cron_command),
                'user' => trim($this->new_cron_user),
                'description' => trim((string) $this->new_description) ?: null,
            ]
        );
        $this->template_save_name = null;
        $this->flash_success = __('Template saved for this organization.');
        $this->flash_error = null;
    }

    public function deleteOrgCronTemplate(string $templateId): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org === null) {
            return;
        }
        $this->authorize('update', $org);
        OrganizationCronJobTemplate::query()
            ->where('organization_id', $org->id)
            ->whereKey($templateId)
            ->firstOrFail()
            ->delete();
        $this->flash_success = __('Template removed.');
        $this->flash_error = null;
    }

    public function applyOrgCronTemplate(string $templateId): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org === null) {
            return;
        }
        $tpl = OrganizationCronJobTemplate::query()
            ->where('organization_id', $org->id)
            ->whereKey($templateId)
            ->firstOrFail();
        $this->new_cron_expression = $tpl->cron_expression;
        $this->new_cron_command = $tpl->command;
        $this->new_cron_user = $tpl->user;
        $this->new_description = $tpl->description;
        $this->updatedNewCronExpression();
        $this->cron_workspace_tab = 'jobs';
        $this->flash_success = __('Loaded template into the form — review and save.');
        $this->flash_error = null;
    }

    public function saveOrgCronMaintenance(): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org === null) {
            return;
        }
        $this->authorize('update', $org);

        $this->validate([
            'org_maintenance_until_local' => ['nullable', 'string', 'max:32'],
            'org_maintenance_note' => ['nullable', 'string', 'max:500'],
        ]);

        $until = null;
        if ($this->org_maintenance_until_local !== null && trim($this->org_maintenance_until_local) !== '') {
            try {
                $until = Carbon::parse($this->org_maintenance_until_local, config('app.timezone'));
            } catch (\Throwable) {
                $this->addError('org_maintenance_until_local', __('Invalid date/time.'));

                return;
            }
        }

        $org->update([
            'cron_maintenance_until' => $until,
            'cron_maintenance_note' => trim($this->org_maintenance_note) ?: null,
        ]);
        $this->flash_success = __('Maintenance window saved. Managed cron lines are omitted until then.');
        $this->flash_error = null;
    }

    public function clearOrgCronMaintenance(): void
    {
        $this->authorize('update', $this->server);
        $org = $this->server->organization;
        if ($org === null) {
            return;
        }
        $this->authorize('update', $org);
        $org->update([
            'cron_maintenance_until' => null,
            'cron_maintenance_note' => null,
        ]);
        $this->org_maintenance_until_local = null;
        $this->org_maintenance_note = '';
        $this->flash_success = __('Maintenance window cleared.');
        $this->flash_error = null;
    }

    public function openLogsModal(string $jobId): void
    {
        $this->viewing_logs_job_id = $jobId;
    }

    public function closeLogsModal(): void
    {
        $this->viewing_logs_job_id = null;
    }

    protected function resetForm(): void
    {
        $this->new_cron_expression = '* * * * *';
        $this->frequency_preset = 'every_minute';
        $this->command_preset = 'custom';
        $this->new_cron_command = '';
        $this->new_cron_user = trim((string) $this->server->ssh_user) ?: 'root';
        $this->new_description = null;
        $this->new_site_id = null;
        $this->new_schedule_timezone = config('app.timezone');
        $this->new_overlap_policy = ServerCronJob::OVERLAP_ALLOW;
        $this->new_alert_on_failure = false;
        $this->new_alert_on_pattern_match = false;
        $this->new_alert_pattern = null;
        $this->new_env_prefix = null;
        $this->new_depends_on_job_id = null;
        $this->new_maintenance_tag = null;
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load(['cronJobs.site.domains', 'sites', 'organization.cronJobTemplates']);

        $jobsQuery = ServerCronJob::query()
            ->where('server_id', $this->server->id)
            ->with(['dependsOn', 'site.domains']);

        if (trim($this->cron_job_search) !== '') {
            $term = '%'.trim($this->cron_job_search).'%';
            $jobsQuery->where(function ($q) use ($term): void {
                $q->where('command', 'like', $term)
                    ->orWhere('description', 'like', $term);
            });
        }

        $filteredCronJobs = $jobsQuery
            ->orderBy('description')
            ->orderBy('id')
            ->get();

        $recentCronRuns = ServerCronJobRun::query()
            ->whereHas('cronJob', fn ($q) => $q->where('server_id', $this->server->id))
            ->with(['cronJob'])
            ->orderByDesc('started_at')
            ->limit(100)
            ->get();

        $org = $this->server->organization;
        $canUpdateOrg = $org !== null && auth()->user()?->can('update', $org);

        if (! $canUpdateOrg && $this->cron_workspace_tab === 'maintenance') {
            $this->cron_workspace_tab = 'jobs';
        }

        return view('livewire.servers.workspace-cron', [
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'viewingLogJob' => $this->viewing_logs_job_id
                ? ServerCronJob::query()
                    ->where('server_id', $this->server->id)
                    ->find($this->viewing_logs_job_id)
                : null,
            'commandInstallPresets' => $this->commandInstallPresets(),
            'crontabInspectUserChoices' => $this->crontabInspectUserChoices(),
            'runAsUserDatalistChoices' => $this->runAsUserDatalistChoices(),
            'cronRunEchoSubscribable' => $this->cronRunEchoSubscribable(),
            'filteredCronJobs' => $filteredCronJobs,
            'recentCronRuns' => $recentCronRuns,
            'canUpdateOrg' => $canUpdateOrg,
            'orgCronTemplates' => $org?->cronJobTemplates ?? collect(),
            'dependsJobChoices' => ServerCronJob::query()
                ->where('server_id', $this->server->id)
                ->when($this->editing_job_id, fn ($q) => $q->whereKeyNot($this->editing_job_id))
                ->orderBy('description')
                ->get(),
        ]);
    }
}
