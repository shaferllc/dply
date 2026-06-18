<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerManageAction;
use App\Services\Servers\MiseInstallScriptBuilder;
use App\Services\SshConnection;
use Livewire\Attributes\Lazy;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesServerMiseRuntimes
{
    /**
     * Lazy-loaded list of mise's upstream-available versions per runtime. Empty
     * until the operator clicks "Load versions" on the Tools → mise card; then
     * cached for the lifetime of the Livewire component instance so the dropdown
     * doesn't re-SSH on every render. Shape: ['node' => ['22.7.0', '20.16.0', …], …].
     * Filtered to stable releases only (pre/rc/beta/alpha tags stripped).
     *
     * @var array<string, list<string>>
     */
    public array $mise_available_versions = [];

    /** Per-runtime "loading versions" state for the dropdown spinner. */
    public ?string $mise_loading_versions_for = null;

    /** True while a synchronous inventory reprobe runs after a mise action completes. */
    public bool $miseReprobePending = false;

    /**
     * Install a runtime version under the deploy user's mise and activate it as
     * the global default (`mise use --global`). Without activation, mise warns
     * that the version is "installed but not activated". The Tools tab's
     * "Install & activate" button is wired here.
     */
    public function miseInstallRuntime(string $runtime, string $version): void
    {
        $this->dispatchMiseRuntimeAction(
            runtime: $runtime,
            version: $version,
            kind: 'install',
            taskName: 'mise-runtime:install',
            labelTemplate: __('Installing and activating :runtime :version'),
        );
    }

    /**
     * Uninstall a runtime version from the deploy user's mise. Blocked when
     * the requested version is the current global default — operator must
     * pick a new default first (mise itself errors out the same way).
     */
    public function miseUninstallRuntime(string $runtime, string $version): void
    {
        $current = $this->miseCurrentRuntimeDefault($runtime);
        if ($current !== null && $current === trim($version)) {
            $this->toastError(__('Cannot uninstall :runtime :version while it is the global default — set a different version as default first.', [
                'runtime' => $runtime,
                'version' => $version,
            ]));

            return;
        }

        $this->dispatchMiseRuntimeAction(
            runtime: $runtime,
            version: $version,
            kind: 'uninstall',
            taskName: 'mise-runtime:uninstall',
            labelTemplate: __('Uninstalling :runtime :version'),
        );
    }

    /**
     * Open the confirmation modal before uninstalling a mise runtime version.
     */
    public function promptMiseUninstallRuntime(string $runtime, string $version): void
    {
        $runtime = strtolower(trim($runtime));
        $version = trim($version);

        if ($version === '') {
            return;
        }

        $catalog = config('server_manage.mise_runtimes', []);
        $label = is_array($catalog[$runtime] ?? null)
            ? (string) ($catalog[$runtime]['label'] ?? $runtime)
            : $runtime;

        $confirm = __('Uninstall :runtime :version? The deploy user\'s mise data directory drops the install; sites already pinned to this version will fall back to the runtime default.', [
            'runtime' => $label,
            'version' => $version,
        ]);

        $this->openConfirmActionModal(
            'miseUninstallRuntime',
            [$runtime, $version],
            __('Uninstall :v', ['v' => $version]),
            $confirm,
            __('Uninstall :runtime :v', ['runtime' => $label, 'v' => $version]),
            true,
        );
    }

    /**
     * Set a runtime version as the deploy user's global default (`mise use
     * --global`). Installs the version as a side-effect if it isn't already
     * present, so this doubles as a "switch to this version" affordance.
     */
    public function miseSetRuntimeDefault(string $runtime, string $version): void
    {
        $this->dispatchMiseRuntimeAction(
            runtime: $runtime,
            version: $version,
            kind: 'default',
            taskName: 'mise-runtime:default',
            labelTemplate: __('Setting :runtime :version as default'),
        );
    }

    /**
     * Shared plumbing for the three mise runtime actions. Validates inputs,
     * builds the right bash via {@see MiseInstallScriptBuilder}, and dispatches
     * through the queued manage-action pipeline so output flows into the
     * existing console-action banner.
     */
    protected function dispatchMiseRuntimeAction(
        string $runtime,
        string $version,
        string $kind,
        string $taskName,
        string $labelTemplate,
    ): void {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot manage server runtimes.'));

            return;
        }

        $runtime = strtolower(trim($runtime));
        $version = trim($version);

        if (! in_array($runtime, MiseInstallScriptBuilder::supportedRuntimes(), true)) {
            $this->toastError(__('Unsupported runtime: :runtime.', ['runtime' => $runtime]));

            return;
        }

        // Loose version validation — accept semver-ish, plain digits, and mise
        // shorthand like "lts" or "20". Reject anything with shell metacharacters
        // even though the builder escapes via `escapeshellarg`, since the value
        // also lands in console-action labels we surface to the operator.
        if ($version === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
            $this->toastError(__('Invalid version: :version.', ['version' => $version]));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before managing runtimes.'));

            return;
        }

        $deployUser = trim((string) ($this->server->ssh_user ?? '')) !== ''
            ? (string) $this->server->ssh_user
            : (string) config('server_provision.deploy_ssh_user', 'dply');
        if ($deployUser === '' || $deployUser === 'root') {
            $this->toastError(__('This server has no deploy user configured; cannot manage mise runtimes.'));

            return;
        }

        $builder = app(MiseInstallScriptBuilder::class);
        $runtimeLines = match ($kind) {
            'install', 'default' => $builder->installRuntimeForUserLines($deployUser, $runtime, $version),
            'uninstall' => $builder->uninstallRuntimeVersionForUserLines($deployUser, $runtime, $version),
            default => [],
        };
        $lines = $kind === 'uninstall'
            ? $runtimeLines
            : array_merge($builder->activateForUserLines($deployUser), $runtimeLines);
        if ($lines === []) {
            $this->toastError(__('Could not build the runtime script for :runtime :version.', [
                'runtime' => $runtime,
                'version' => $version,
            ]));

            return;
        }

        // The builder emits ash-safe lines; join them with set -e so a mid-script
        // failure surfaces in the banner rather than silently passing.
        $script = "set -e\n".implode("\n", $lines)."\n";
        $label = strtr($labelTemplate, [':runtime' => $runtime, ':version' => $version]);

        $this->dispatchQueuedManageScript(
            $this->server->fresh() ?? $this->server,
            $taskName.':'.$runtime.'@'.$version,
            $script,
            300, // mise installs (Python/Ruby builds) can take a few minutes.
            $label.' '.__('finished.'),
            __('TaskRunner (SSH)').' — '.$label,
            $label,
        );
    }

    /**
     * Populate {@see $mise_available_versions} for one runtime by SSHing
     * `mise ls-remote <tool>` as the deploy user. Filters to stable releases
     * (drops pre/rc/beta/alpha/dev tags) and caps the dropdown size — there's
     * no value in showing the operator hundreds of Node patch releases.
     *
     * Runs synchronously and blocks the Livewire request for ~1–3s on a warm
     * mise plugin; first-ever invocation can take longer if mise has to clone
     * the plugin repo. Errors surface as a toast and a null entry so the UI
     * can offer "try again" without re-fetching on every render.
     */
    public function loadMiseAvailableVersions(string $runtime): void
    {
        $this->authorize('update', $this->server);

        $runtime = strtolower(trim($runtime));
        if (! in_array($runtime, MiseInstallScriptBuilder::supportedRuntimes(), true)) {
            $this->toastError(__('Unsupported runtime: :runtime.', ['runtime' => $runtime]));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before loading versions.'));

            return;
        }

        $deployUser = trim((string) ($this->server->ssh_user ?? '')) !== ''
            ? (string) $this->server->ssh_user
            : (string) config('server_provision.deploy_ssh_user', 'dply');
        if ($deployUser === '' || $deployUser === 'root') {
            $this->toastError(__('This server has no deploy user configured; cannot list mise versions.'));

            return;
        }

        $this->mise_loading_versions_for = $runtime;

        try {
            $userArg = escapeshellarg($deployUser);
            $toolArg = escapeshellarg($runtime);
            $script = "sudo -u {$userArg} -i mise ls-remote {$toolArg} 2>/dev/null || true";
            $ssh = new SshConnection($this->server, 'root');
            $output = $ssh->exec('/bin/sh -c '.escapeshellarg($script), 30);
            $ssh->disconnect();

            $versions = $this->filterStableMiseVersions($output);
            $this->mise_available_versions[$runtime] = $versions;

            if ($versions === []) {
                $this->toastError(__(':runtime: no stable versions returned. Is the mise plugin installed?', ['runtime' => $runtime]));
            }
        } catch (\Throwable $e) {
            $this->toastError(__(':runtime versions: :err', ['runtime' => $runtime, 'err' => $e->getMessage()]));
        } finally {
            $this->mise_loading_versions_for = null;
        }
    }

    /**
     * Strip pre-release tags and dedupe `mise ls-remote` output down to the
     * shortlist the dropdown actually wants. Versions sort descending so the
     * latest is at the top.
     *
     * @return list<string>
     */
    protected function filterStableMiseVersions(string $output): array
    {
        $versions = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Drop anything that doesn't start with a digit (e.g. "system",
            // header noise) and any release tagged pre/rc/beta/alpha/dev.
            if (! preg_match('/^\d/', $line)) {
                continue;
            }
            if (preg_match('/-(?:pre|rc|beta|alpha|dev|nightly|preview|next)/i', $line)) {
                continue;
            }
            $versions[] = $line;
        }
        $versions = array_values(array_unique($versions));
        usort($versions, fn (string $a, string $b) => version_compare($b, $a));

        // Cap at a reasonable size — operators rarely need anything older.
        return array_slice($versions, 0, 60);
    }

    /**
     * Read the current global default for a runtime from the cached probe
     * snapshot. Returns null when the runtime hasn't been probed yet or has
     * no default. Used by miseUninstallRuntime() to refuse to uninstall the
     * active default (mise itself would refuse anyway, but failing fast in
     * Livewire gives a friendlier toast than the SSH banner does).
     */
    protected function miseCurrentRuntimeDefault(string $runtime): ?string
    {
        $meta = $this->server->fresh()->meta ?? [];
        $runtimes = is_array($meta['manage_mise_runtimes'] ?? null) ? $meta['manage_mise_runtimes'] : [];
        $entry = $runtimes[$runtime] ?? null;
        if (! is_array($entry)) {
            return null;
        }
        $active = $entry['active'] ?? null;

        return is_string($active) && $active !== '' ? $active : null;
    }

    /**
     * @return array<string, array{kind: string, version: string, status: string, message: string}>
     */
    protected function activeMiseRuntimeOperations(): array
    {
        $rows = ServerManageAction::query()
            ->where('server_id', $this->server->id)
            ->where('task_name', 'like', 'mise-runtime:%')
            ->whereIn('status', [
                ServerManageAction::STATUS_QUEUED,
                ServerManageAction::STATUS_RUNNING,
            ])
            ->orderByDesc('created_at')
            ->get(['task_name', 'status']);

        $ops = [];

        foreach ($rows as $row) {
            if (! preg_match('/^mise-runtime:(install|uninstall|default):([^@]+)@(.+)$/', (string) $row->task_name, $matches)) {
                continue;
            }

            $runtime = strtolower($matches[2]);
            if (isset($ops[$runtime])) {
                continue;
            }

            $kind = $matches[1];
            $version = $matches[3];

            $ops[$runtime] = [
                'kind' => $kind,
                'version' => $version,
                'status' => (string) $row->status,
                'message' => match ($kind) {
                    'install' => __('Installing :version…', ['version' => $version]),
                    'uninstall' => __('Uninstalling :version…', ['version' => $version]),
                    'default' => __('Setting :version as default…', ['version' => $version]),
                    default => __('Working…'),
                },
            ];
        }

        return $ops;
    }

    protected function runPostMiseInventoryRefresh(): void
    {
        if (! $this->canRunInventoryProbe()) {
            return;
        }

        $this->miseReprobePending = true;

        try {
            $this->refreshServerInventoryDetails();
        } finally {
            $this->miseReprobePending = false;
            $this->server->refresh();
        }
    }
}
