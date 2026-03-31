<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServerProvider;
use App\Livewire\Servers\Create as ServersCreate;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Modules\TaskRunner\Models\Task as TaskRunnerTask;
use App\Services\DigitalOceanService;
use App\Support\ServerProviderGate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * Local / CI helper: run as dply.demo_user_email (or --email), use that user’s first
 * organization by membership time unless --org-slug is set, attach a DigitalOcean token to
 * that org, remove prior demo droplets (dply-demo-* or --name), then create a droplet via
 * the same path as the UI for live monitoring.
 *
 * Token source (first match): --token, DPLY_DEMO_DO_TOKEN, DIGITALOCEAN_TOKEN.
 * Never commit tokens; rotate any token that was exposed in chat or logs.
 */
class DemoDigitalOceanServerCommand extends Command
{
    protected $signature = 'dply:demo-do-server
                            {--email= : User email to act as (default: config dply.demo_user_email)}
                            {--password=password : Password when creating a brand-new user only}
                            {--token= : DigitalOcean API token (else env DPLY_DEMO_DO_TOKEN or DIGITALOCEAN_TOKEN)}
                            {--org-slug= : Force this org slug (else first org for that user, or demo_org_slug if none)}
                            {--region= : Region slug (default: config dply.demo_do_region)}
                            {--size= : Droplet size slug (default: config dply.demo_do_size)}
                            {--name= : Server name (default: dply-demo-<random>)}
                            {--wait : Poll server status and setup until done, failed, or timeout}
                            {--wait-timeout=1800 : Max seconds when using --wait}';

    protected $description = 'Provision a DO droplet as the demo user into their first org (for UI feedback); replaces prior dply-demo-* servers there';

    public function handle(): int
    {
        if (! ServerProviderGate::enabled('digitalocean')) {
            $this->error('DigitalOcean server provider is disabled (feature gate).');

            return self::FAILURE;
        }

        $token = $this->resolveToken();
        if ($token === null || trim($token) === '') {
            $this->error('Set a token: --token=... or DPLY_DEMO_DO_TOKEN or DIGITALOCEAN_TOKEN in .env');

            return self::FAILURE;
        }

        try {
            new DigitalOceanService($token)->getRegions();
        } catch (\Throwable $e) {
            $this->error('DigitalOcean API check failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $email = (string) ($this->option('email') ?: config('dply.demo_user_email', 'tom.shafer@gmail.com'));
        $password = (string) $this->option('password');

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => 'Dply Demo',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );

        if ($user->wasRecentlyCreated) {
            $this->info("Created user: {$email}");
        } else {
            $this->info("Using existing user: {$email}");
        }

        $org = $this->resolveOrganizationForDemo($user, $email);
        if ($org === null) {
            return self::FAILURE;
        }

        $this->info("Organization: {$org->name} ({$org->slug})");

        $credential = ProviderCredential::query()->updateOrCreate(
            [
                'organization_id' => $org->id,
                'provider' => 'digitalocean',
                'name' => 'CLI demo token',
            ],
            [
                'user_id' => $user->id,
                'credentials' => ['api_token' => $token],
            ]
        );

        $this->info('Provider credential saved: '.$credential->id);

        $region = (string) ($this->option('region') ?: config('dply.demo_do_region', 'nyc1'));
        $size = (string) ($this->option('size') ?: config('dply.demo_do_size', 's-1vcpu-1gb'));
        $serverName = (string) ($this->option('name') ?: 'dply-demo-'.Str::lower(Str::random(6)));

        $this->deletePriorDemoServers($org, $token, $serverName);

        session(['current_organization_id' => $org->id]);

        $lw = Livewire::actingAs($user)
            ->test(ServersCreate::class)
            ->set('form.type', 'digitalocean')
            ->set('form.name', $serverName)
            ->set('form.provider_credential_id', (string) $credential->id)
            ->set('form.region', $region)
            ->set('form.size', $size)
            ->call('store');

        if ($lw->errors()->isNotEmpty()) {
            $this->error('Could not create server: '.$lw->errors()->toJson());

            return self::FAILURE;
        }

        $server = Server::query()
            ->where('organization_id', $org->id)
            ->where('name', $serverName)
            ->latest()
            ->first();

        if ($server === null) {
            $this->error('Server row not found after create. Did store() redirect without persisting?');

            return self::FAILURE;
        }

        $this->info("Server queued: {$server->id} — {$server->name} (status={$server->status})");
        $this->line('Droplet provisioning runs in the queue (<fg=yellow>php artisan queue:work</>).');

        if ($this->option('wait')) {
            $this->waitForFeedback($server, (int) $this->option('wait-timeout'));
        } else {
            $this->line('Run with <fg=cyan>--wait</> to poll status, IP, setup, and TaskRunner task until finished.');
        }

        return self::SUCCESS;
    }

    /**
     * Prefer the user’s first organization (by organization_user.created_at) so the droplet
     * appears in the same context as the dashboard when no org is selected in session.
     * With --org-slug, use that org (attach as owner if needed). If the user has no orgs yet,
     * create the legacy demo org from config (CI / fresh CLI users).
     */
    private function resolveOrganizationForDemo(User $user, string $email): ?Organization
    {
        $slugOption = $this->option('org-slug');
        if (is_string($slugOption) && trim($slugOption) !== '') {
            $slug = trim($slugOption);
            $org = Organization::query()->where('slug', $slug)->first();
            if ($org === null) {
                $this->error("Organization not found for slug: {$slug}");

                return null;
            }
            if (! $org->hasMember($user)) {
                $org->users()->attach($user->id, ['role' => 'owner']);
            }
            $org->attachUserToDefaultTeam($user);

            return $org;
        }

        $org = $user->organizations()
            ->orderByPivot('created_at')
            ->orderBy('organizations.id')
            ->first();

        if ($org !== null) {
            $org->attachUserToDefaultTeam($user);

            return $org;
        }

        $fallbackSlug = (string) config('dply.demo_org_slug', 'dply-automated-demo');
        $org = Organization::query()->firstOrCreate(
            ['slug' => $fallbackSlug],
            [
                'name' => 'Dply automated demo',
                'email' => $email,
            ]
        );
        if (! $org->hasMember($user)) {
            $org->users()->attach($user->id, ['role' => 'owner']);
        }
        $org->attachUserToDefaultTeam($user);
        $this->warn("User had no organizations; created or attached fallback org «{$fallbackSlug}».");

        return $org;
    }

    /**
     * Drop prior CLI demo droplets so each run starts fresh (same org).
     *
     * Targets DigitalOcean servers whose name starts with dply-demo- or matches the next server name.
     */
    private function deletePriorDemoServers(Organization $org, string $token, string $nextServerName): void
    {
        $servers = Server::query()
            ->where('organization_id', $org->id)
            ->where('provider', ServerProvider::DigitalOcean)
            ->where(function ($q) use ($nextServerName): void {
                $q->where('name', 'like', 'dply-demo-%')
                    ->orWhere('name', $nextServerName);
            })
            ->get();

        if ($servers->isEmpty()) {
            return;
        }

        $do = new DigitalOceanService($token);

        foreach ($servers as $server) {
            $this->line("Removing prior demo server: {$server->name} ({$server->id})");
            if (filled($server->provider_id)) {
                try {
                    $do->destroyDroplet((int) $server->provider_id);
                } catch (\Throwable $e) {
                    $this->warn('Could not destroy DigitalOcean droplet '.$server->provider_id.': '.$e->getMessage());
                }
            }
            $server->delete();
        }
    }

    private function resolveToken(): ?string
    {
        $opt = $this->option('token');
        if (is_string($opt) && trim($opt) !== '') {
            return trim($opt);
        }

        $a = env('DPLY_DEMO_DO_TOKEN');
        if (is_string($a) && trim($a) !== '') {
            return trim($a);
        }

        $b = env('DIGITALOCEAN_TOKEN');
        if (is_string($b) && trim($b) !== '') {
            return trim($b);
        }

        return null;
    }

    private function waitForFeedback(Server $server, int $maxSeconds): void
    {
        $deadline = time() + max(30, $maxSeconds);
        $lastLine = '';

        $this->info('Waiting for IP, ready state, and stack setup (Ctrl+C to stop watching)…');

        while (time() < $deadline) {
            $server->refresh();
            $taskId = is_array($server->meta) ? ($server->meta['provision_task_id'] ?? null) : null;
            $taskStatus = null;
            if (is_string($taskId) && $taskId !== '') {
                $task = TaskRunnerTask::query()->find($taskId);
                $taskStatus = $task?->status?->value;
            }

            $line = sprintf(
                '[%s] server_status=%s ip=%s setup_status=%s task=%s',
                now()->format('H:i:s'),
                $server->status,
                $server->ip_address ?: '—',
                $server->setup_status ?? '—',
                $taskStatus ?? '—'
            );

            if ($line !== $lastLine) {
                $this->line($line);
                $lastLine = $line;
            }

            if ($server->status === Server::STATUS_ERROR) {
                $this->error('Server entered error state.');

                return;
            }

            if ($server->status === Server::STATUS_READY) {
                $setup = $server->setup_status;
                if ($setup === Server::SETUP_STATUS_DONE) {
                    $this->info('Setup finished successfully.');

                    return;
                }
                if ($setup === Server::SETUP_STATUS_FAILED) {
                    $this->error('Stack setup failed. Check task_runner_tasks.output for this server’s provision_task_id in meta.');

                    return;
                }
                if ($setup === null && time() > $deadline - 30) {
                    $this->warn('Server is ready but setup_status is still empty — is the queue worker running?');
                }
            }

            sleep(5);
        }

        $this->warn('Wait timeout reached; server may still be provisioning. Keep queue:work running and check the Servers UI.');
    }
}
