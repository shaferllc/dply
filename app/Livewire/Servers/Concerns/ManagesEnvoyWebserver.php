<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceWebserver;
use App\Models\ConsoleAction;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\EnvoyCustomClustersConfig;
use App\Services\Servers\EnvoyCustomListenersConfig;
use App\Services\Servers\EnvoyCustomVirtualHostsConfig;
use App\Services\Servers\EnvoyStaticConfigOptions;
use App\Services\SshConnection;
use Illuminate\Support\Facades\DB;

/**
 * Envoy edge-proxy configuration for {@see WorkspaceWebserver}.
 *
 * Extracted verbatim from the host component. Every public property/method name
 * is unchanged, so Livewire snapshots and wire:* bindings keep resolving against
 * the composed class.
 */
trait ManagesEnvoyWebserver
{
    /** @var array<string, string> */
    public array $envoy_static_form = [];

    public bool $envoy_static_loaded = false;

    public ?string $envoy_static_flash = null;

    public ?string $envoy_static_error = null;

    /** @var array<string, array{endpoints: list<string>, values: array<string, string>}> */
    public array $envoy_clusters_form = [];

    /** @var array<string, string> */
    public array $envoy_clusters_endpoints_text = [];

    public bool $envoy_clusters_loaded = false;

    public ?string $envoy_clusters_flash = null;

    public ?string $envoy_clusters_error = null;

    public bool $envoy_clusters_show_add = false;

    /** @var array{name: string, endpoints: string, connect_timeout: string, lb_policy: string} */
    public array $envoy_clusters_new = [
        'name' => '',
        'endpoints' => '',
        'connect_timeout' => '5s',
        'lb_policy' => 'ROUND_ROBIN',
    ];

    /** @var array<string, array{domains: list<string>, cluster: string}> */
    public array $envoy_virtualhosts_form = [];

    /** @var array<string, string> */
    public array $envoy_virtualhosts_domains_text = [];

    public bool $envoy_virtualhosts_loaded = false;

    public ?string $envoy_virtualhosts_flash = null;

    public ?string $envoy_virtualhosts_error = null;

    public bool $envoy_virtualhosts_show_add = false;

    /** @var array{name: string, domains: string, cluster: string} */
    public array $envoy_virtualhosts_new = [
        'name' => '',
        'domains' => '',
        'cluster' => '',
    ];

    /** @var array<string, array{address: string, port: int|string, mode: string, default_cluster: string}> */
    public array $envoy_listeners_form = [];

    public bool $envoy_listeners_loaded = false;

    public ?string $envoy_listeners_flash = null;

    public ?string $envoy_listeners_error = null;

    public bool $envoy_listeners_show_add = false;

    /** @var array{name: string, address: string, port: string, mode: string, default_cluster: string} */
    public array $envoy_listeners_new = [
        'name' => '',
        'address' => '0.0.0.0',
        'port' => '',
        'mode' => 'shared',
        'default_cluster' => '',
    ];

    public function loadEnvoyStaticConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->envoy_static_error = __('Provisioning and SSH must be ready before reading envoy.yaml.');

            return;
        }

        try {
            $result = app(EnvoyStaticConfigOptions::class)->read($this->server);
            $this->envoy_static_form = $result['values'];
            $this->envoy_static_loaded = true;
            $this->envoy_static_flash = null;
            $this->envoy_static_error = $result['unreadable']
                ? __('Could not read /etc/envoy/envoy.yaml — check sudo permissions for the deploy user.')
                : null;
        } catch (\Throwable $e) {
            $this->envoy_static_error = __('Failed to read Envoy static config: :msg', ['msg' => $e->getMessage()]);
            $this->envoy_static_loaded = false;
        }
    }

    public function saveEnvoyStaticConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->envoy_static_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->envoy_static_error = __('Provisioning and SSH must be ready before saving envoy.yaml.');

            return;
        }

        $this->envoy_static_flash = null;
        $this->envoy_static_error = null;

        $consoleId = $this->seedManageConsoleAction(
            $this->server->fresh(),
            (string) __('Save Envoy static settings'),
        );
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            app(EnvoyStaticConfigOptions::class)
                ->save($this->server, $this->envoy_static_form, $emitter);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'error' => null,
                'updated_at' => now(),
            ]);
            $this->envoy_static_flash = __('Envoy static settings saved and Envoy restarted.');
            $this->loadEnvoyStaticConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_static_error = $e->getMessage();
        }
    }

    public function repairEnvoyAdminApi(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot repair Envoy config.'));

            return;
        }

        if ($this->server->edgeProxy() !== 'envoy') {
            $this->toastError(__('This server does not have Envoy as its edge proxy.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready.'));

            return;
        }

        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Repair Envoy admin interface'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyStaticConfigOptions::class)
                ->repairAdminDefaults($this->server, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->toastSuccess(__('Envoy admin defaults restored on 127.0.0.1:9901.'));
            $this->loadEnvoyStaticConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->toastError($e->getMessage());
        }
    }

    public function startEnvoyService(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot start Envoy.'));

            return;
        }

        if ($this->server->edgeProxy() !== 'envoy') {
            $this->toastError(__('This server does not have Envoy as its edge proxy.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready.'));

            return;
        }

        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Start Envoy'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyStaticConfigOptions::class)
                ->startEnvoyService($this->server, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->toastSuccess(__('Envoy is running. Try the admin link again.'));
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->toastError($e->getMessage());
        }
    }

    public function drainEnvoyListeners(): void
    {
        $this->runEnvoyAdminPostAction(
            __('Drain Envoy listeners'),
            '/drain_listeners?graceful',
            __('Envoy listeners are draining — existing connections finish before new ones are accepted.'),
        );
    }

    public function healthcheckFailEnvoy(): void
    {
        $this->runEnvoyAdminPostAction(
            __('Fail Envoy health checks'),
            '/healthcheck/fail',
            __('Envoy health checks marked failed — upstreams should stop receiving new traffic if wired to health status.'),
        );
    }

    private function runEnvoyAdminPostAction(string $title, string $path, string $successMessage): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot run Envoy maintenance actions.'));

            return;
        }

        if ($this->server->edgeProxy() !== 'envoy' || ! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready.'));

            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), $title);
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);
        $emitter = new ConsoleEmitter($consoleId);

        try {
            $ssh = new SshConnection($this->server);
            $script = sprintf(
                'curl -4sf --max-time 10 -X POST %s 2>&1 || curl -4sf --max-time 10 %s 2>&1',
                escapeshellarg('http://127.0.0.1:9901'.$path),
                escapeshellarg('http://127.0.0.1:9901'.$path),
            );
            $output = $ssh->exec($script, 20);
            if (($ssh->lastExecExitCode() ?? 1) !== 0) {
                throw new \RuntimeException(trim((string) $output) !== '' ? trim((string) $output) : 'Envoy admin request failed.');
            }
            $emitter->info(trim((string) $output));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->toastSuccess($successMessage);
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->toastError($e->getMessage());
        }
    }

    private function refreshEnvoyLiveStateAfterServiceAction(): void
    {
        if ($this->server->edgeProxy() !== 'envoy') {
            return;
        }

        $this->ensureEngineLiveState(forceFresh: true);
    }

    public function loadEnvoyClustersConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->envoy_clusters_error = __('Provisioning and SSH must be ready before reading envoy.yaml.');

            return;
        }

        try {
            $clusters = app(EnvoyCustomClustersConfig::class)->read($this->server);
            $form = [];
            $text = [];
            foreach ($clusters as $cluster) {
                $name = (string) ($cluster['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $form[$name] = [
                    'endpoints' => $cluster['endpoints'] ?? [],
                    'values' => [
                        'connect_timeout' => (string) ($cluster['connect_timeout'] ?? '5s'),
                        'lb_policy' => (string) ($cluster['lb_policy'] ?? 'ROUND_ROBIN'),
                    ],
                ];
                $text[$name] = implode("\n", $cluster['endpoints'] ?? []);
            }
            $this->envoy_clusters_form = $form;
            $this->envoy_clusters_endpoints_text = $text;
            $this->envoy_clusters_loaded = true;
            $this->envoy_clusters_flash = null;
            $this->envoy_clusters_error = null;
        } catch (\Throwable $e) {
            $this->envoy_clusters_error = __('Failed to read custom clusters: :msg', ['msg' => $e->getMessage()]);
            $this->envoy_clusters_loaded = false;
        }
    }

    public function saveEnvoyClustersConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->envoy_clusters_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->envoy_clusters_error = __('Provisioning and SSH must be ready.');

            return;
        }

        $clusters = [];
        foreach ($this->envoy_clusters_endpoints_text as $name => $raw) {
            if (! isset($this->envoy_clusters_form[$name])) {
                continue;
            }
            $endpoints = array_values(array_filter(
                array_map('trim', preg_split('/\R/', (string) $raw) ?: []),
                fn (string $line): bool => $line !== '',
            ));
            $values = $this->envoy_clusters_form[$name]['values'] ?? [];
            $clusters[] = [
                'name' => $name,
                'endpoints' => $endpoints,
                'connect_timeout' => (string) ($values['connect_timeout'] ?? '5s'),
                'lb_policy' => (string) ($values['lb_policy'] ?? 'ROUND_ROBIN'),
            ];
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Envoy custom clusters'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomClustersConfig::class)
                ->save($this->server, $clusters, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_flash = __('Custom clusters saved and edge routing regenerated.');
            $this->loadEnvoyClustersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_error = $e->getMessage();
        }
    }

    public function openAddEnvoyClusterForm(): void
    {
        $this->envoy_clusters_show_add = true;
        $this->envoy_clusters_new = [
            'name' => '',
            'endpoints' => '',
            'connect_timeout' => '5s',
            'lb_policy' => 'ROUND_ROBIN',
        ];
        $this->envoy_clusters_error = null;
        $this->envoy_clusters_flash = null;
    }

    public function cancelAddEnvoyClusterForm(): void
    {
        $this->envoy_clusters_show_add = false;
    }

    public function submitAddEnvoyCluster(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $name = trim((string) ($this->envoy_clusters_new['name'] ?? ''));
        $endpoints = array_values(array_filter(
            array_map('trim', preg_split('/\R/', (string) ($this->envoy_clusters_new['endpoints'] ?? '')) ?: []),
            fn (string $line): bool => $line !== '',
        ));
        $values = [
            'connect_timeout' => (string) ($this->envoy_clusters_new['connect_timeout'] ?? '5s'),
            'lb_policy' => (string) ($this->envoy_clusters_new['lb_policy'] ?? 'ROUND_ROBIN'),
        ];

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Envoy cluster: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomClustersConfig::class)
                ->addCluster($this->server, $name, $endpoints, $values, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_flash = __('Cluster added.');
            $this->envoy_clusters_show_add = false;
            $this->loadEnvoyClustersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_error = $e->getMessage();
        }
    }

    public function removeEnvoyCluster(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Envoy cluster: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomClustersConfig::class)
                ->removeCluster($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_flash = __('Cluster removed.');
            $this->loadEnvoyClustersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_clusters_error = $e->getMessage();
        }
    }

    public function loadEnvoyVirtualHostsConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->envoy_virtualhosts_error = __('Provisioning and SSH must be ready before reading virtual hosts.');

            return;
        }

        try {
            $virtualHosts = app(EnvoyCustomVirtualHostsConfig::class)->read($this->server);
            $form = [];
            $text = [];
            foreach ($virtualHosts as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $form[$name] = [
                    'domains' => $row['domains'] ?? [],
                    'cluster' => (string) ($row['cluster'] ?? ''),
                ];
                $text[$name] = implode("\n", $row['domains'] ?? []);
            }
            $this->envoy_virtualhosts_form = $form;
            $this->envoy_virtualhosts_domains_text = $text;
            $this->envoy_virtualhosts_loaded = true;
            $this->envoy_virtualhosts_flash = null;
            $this->envoy_virtualhosts_error = null;
        } catch (\Throwable $e) {
            $this->envoy_virtualhosts_error = __('Failed to read custom virtual hosts: :msg', ['msg' => $e->getMessage()]);
            $this->envoy_virtualhosts_loaded = false;
        }
    }

    public function saveEnvoyVirtualHostsConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->envoy_virtualhosts_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->envoy_virtualhosts_error = __('Provisioning and SSH must be ready.');

            return;
        }

        $virtualHosts = [];
        foreach ($this->envoy_virtualhosts_domains_text as $name => $raw) {
            if (! isset($this->envoy_virtualhosts_form[$name])) {
                continue;
            }
            $domains = array_values(array_filter(
                array_map('trim', preg_split('/[\s,]+/', (string) $raw) ?: []),
                fn (string $d): bool => $d !== '',
            ));
            $virtualHosts[] = [
                'name' => $name,
                'domains' => $domains,
                'cluster' => trim((string) ($this->envoy_virtualhosts_form[$name]['cluster'] ?? '')),
            ];
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Envoy custom virtual hosts'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomVirtualHostsConfig::class)
                ->save($this->server, $virtualHosts, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_flash = __('Custom virtual hosts saved and edge routing regenerated.');
            $this->loadEnvoyVirtualHostsConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_error = $e->getMessage();
        }
    }

    public function openAddEnvoyVirtualHostForm(): void
    {
        $this->envoy_virtualhosts_show_add = true;
        $this->envoy_virtualhosts_new = [
            'name' => '',
            'domains' => '',
            'cluster' => '',
        ];
        $this->envoy_virtualhosts_error = null;
        $this->envoy_virtualhosts_flash = null;
    }

    public function cancelAddEnvoyVirtualHostForm(): void
    {
        $this->envoy_virtualhosts_show_add = false;
    }

    public function submitAddEnvoyVirtualHost(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $name = trim((string) ($this->envoy_virtualhosts_new['name'] ?? ''));
        $domains = array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/', (string) ($this->envoy_virtualhosts_new['domains'] ?? '')) ?: []),
            fn (string $d): bool => $d !== '',
        ));
        $cluster = trim((string) ($this->envoy_virtualhosts_new['cluster'] ?? ''));

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Envoy virtual host: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomVirtualHostsConfig::class)
                ->add($this->server, $name, $domains, $cluster, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_flash = __('Virtual host added.');
            $this->envoy_virtualhosts_show_add = false;
            $this->loadEnvoyVirtualHostsConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_error = $e->getMessage();
        }
    }

    public function removeEnvoyVirtualHost(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Envoy virtual host: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomVirtualHostsConfig::class)
                ->remove($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_flash = __('Virtual host removed.');
            $this->loadEnvoyVirtualHostsConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_virtualhosts_error = $e->getMessage();
        }
    }

    public function loadEnvoyListenersConfig(): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->envoy_listeners_error = __('Provisioning and SSH must be ready before reading listeners.');

            return;
        }

        try {
            $listeners = app(EnvoyCustomListenersConfig::class)->read($this->server);
            $form = [];
            foreach ($listeners as $row) {
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $form[$name] = [
                    'address' => (string) ($row['address'] ?? '0.0.0.0'),
                    'port' => (int) ($row['port'] ?? 0),
                    'mode' => (string) ($row['mode'] ?? EnvoyCustomListenersConfig::MODE_SHARED),
                    'default_cluster' => (string) ($row['default_cluster'] ?? ''),
                ];
            }
            $this->envoy_listeners_form = $form;
            $this->envoy_listeners_loaded = true;
            $this->envoy_listeners_flash = null;
            $this->envoy_listeners_error = null;
        } catch (\Throwable $e) {
            $this->envoy_listeners_error = __('Failed to read custom listeners: :msg', ['msg' => $e->getMessage()]);
            $this->envoy_listeners_loaded = false;
        }
    }

    public function saveEnvoyListenersConfig(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->envoy_listeners_error = __('Deployers cannot edit server config.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->envoy_listeners_error = __('Provisioning and SSH must be ready.');

            return;
        }

        $listeners = [];
        foreach ($this->envoy_listeners_form as $name => $values) {
            $listeners[] = [
                'name' => $name,
                'address' => trim((string) ($values['address'] ?? '0.0.0.0')) ?: '0.0.0.0',
                'port' => (int) ($values['port'] ?? 0),
                'mode' => trim((string) ($values['mode'] ?? EnvoyCustomListenersConfig::MODE_SHARED)),
                'default_cluster' => trim((string) ($values['default_cluster'] ?? '')),
            ];
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Save Envoy custom listeners'));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomListenersConfig::class)
                ->save($this->server, $listeners, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_flash = __('Custom listeners saved and edge routing regenerated.');
            $this->loadEnvoyListenersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_error = $e->getMessage();
        }
    }

    public function openAddEnvoyListenerForm(): void
    {
        $this->envoy_listeners_show_add = true;
        $this->envoy_listeners_new = [
            'name' => '',
            'address' => '0.0.0.0',
            'port' => '',
            'mode' => EnvoyCustomListenersConfig::MODE_SHARED,
            'default_cluster' => '',
        ];
        $this->envoy_listeners_error = null;
        $this->envoy_listeners_flash = null;
    }

    public function cancelAddEnvoyListenerForm(): void
    {
        $this->envoy_listeners_show_add = false;
    }

    public function submitAddEnvoyListener(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $fields = [
            'name' => trim((string) ($this->envoy_listeners_new['name'] ?? '')),
            'address' => trim((string) ($this->envoy_listeners_new['address'] ?? '0.0.0.0')) ?: '0.0.0.0',
            'port' => (int) ($this->envoy_listeners_new['port'] ?? 0),
            'mode' => trim((string) ($this->envoy_listeners_new['mode'] ?? EnvoyCustomListenersConfig::MODE_SHARED)),
            'default_cluster' => trim((string) ($this->envoy_listeners_new['default_cluster'] ?? '')),
        ];

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Add Envoy listener: :name', ['name' => $fields['name']]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomListenersConfig::class)
                ->add($this->server, $fields, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_flash = __('Listener added.');
            $this->envoy_listeners_show_add = false;
            $this->loadEnvoyListenersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_error = $e->getMessage();
        }
    }

    public function removeEnvoyListener(string $name): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer() || ! $this->serverOpsReady()) {
            return;
        }

        $consoleId = $this->seedManageConsoleAction($this->server->fresh(), __('Remove Envoy listener: :name', ['name' => $name]));
        DB::table('console_actions')->where('id', $consoleId)->update([
            'status' => ConsoleAction::STATUS_RUNNING,
            'started_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(EnvoyCustomListenersConfig::class)
                ->remove($this->server, $name, new ConsoleEmitter($consoleId));
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_COMPLETED,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_flash = __('Listener removed.');
            $this->loadEnvoyListenersConfig();
            $this->refreshEnvoyLiveStateAfterServiceAction();
        } catch (\Throwable $e) {
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_FAILED,
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'updated_at' => now(),
            ]);
            $this->envoy_listeners_error = $e->getMessage();
        }
    }
}
