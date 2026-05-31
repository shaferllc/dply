<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Jobs\ProvisionSiteSystemdUnitsJob;
use App\Jobs\TearDownSiteSystemdUnitJob;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\Sites\SiteSystemdProvisioner;
use App\Services\Sites\SiteSystemdUnitBuilder;
use Livewire\Component;

/**
 * CRUD + converge for {@see SiteProcess} rows managed as systemd units.
 *
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesSiteSystemdProcesses
{
    public string $new_site_process_type = 'worker';

    public string $new_site_process_name = '';

    public string $new_site_process_command = '';

    /** @var 'units'|'preview' */
    public string $services_workspace_tab = 'units';

    public string $unit_preview_body = '';

    public ?string $preview_process_id = null;

    public function setServicesWorkspaceTab(string $tab): void
    {
        $allowed = ['units', 'preview'];
        $this->services_workspace_tab = in_array($tab, $allowed, true) ? $tab : 'units';
    }

    public function applySystemdPreset(string $preset): void
    {
        $this->authorize('update', $this->site);

        $presets = config('site_systemd_presets', []);
        if (! is_array($presets) || ! isset($presets[$preset]) || ! is_array($presets[$preset])) {
            $this->toastError(__('That preset is not available.'));

            return;
        }

        $entry = $presets[$preset];
        $this->new_site_process_type = (string) ($entry['type'] ?? SiteProcess::TYPE_WORKER);
        $this->new_site_process_name = (string) ($entry['name'] ?? '');
        $this->new_site_process_command = (string) ($entry['command'] ?? '');

        $this->toastSuccess(__('Preset loaded — adjust if needed, then add the unit.'));
    }

    public function addSiteProcess(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->siteSupportsSystemdServices()) {
            $this->toastError(__('This site does not use systemd-managed services.'));

            return;
        }

        $allowedTypes = [SiteProcess::TYPE_WORKER, SiteProcess::TYPE_SCHEDULER, SiteProcess::TYPE_CUSTOM];
        $validated = $this->validate([
            'new_site_process_type' => ['required', 'string', 'in:'.implode(',', $allowedTypes)],
            'new_site_process_name' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_-]+$/'],
            'new_site_process_command' => ['required', 'string', 'max:2000'],
        ], attributes: [
            'new_site_process_type' => __('process type'),
            'new_site_process_name' => __('process name'),
            'new_site_process_command' => __('process command'),
        ]);

        $name = trim($validated['new_site_process_name']);
        if ($name === 'web') {
            $this->addError(
                'new_site_process_name',
                __('The name "web" is reserved for the upstream process.'),
            );

            return;
        }

        if ($this->site->processes()->where('name', $name)->exists()) {
            $this->addError(
                'new_site_process_name',
                __('A process named ":name" already exists for this site.', ['name' => $name]),
            );

            return;
        }

        $this->site->processes()->create([
            'type' => $validated['new_site_process_type'],
            'name' => $name,
            'command' => trim($validated['new_site_process_command']),
            'scale' => 1,
            'is_active' => true,
        ]);

        ProvisionSiteSystemdUnitsJob::dispatch($this->site->id, auth()->id());

        $this->new_site_process_name = '';
        $this->new_site_process_command = '';
        $this->new_site_process_type = SiteProcess::TYPE_WORKER;

        $this->site->load('processes');
        $this->toastSuccess(__('Unit :name added and sync queued.', ['name' => $name]));
    }

    public function removeSiteProcess(string $id): void
    {
        $this->authorize('update', $this->site);

        $process = $this->site->processes()->whereKey($id)->first();
        if ($process === null) {
            return;
        }

        if ($process->type === SiteProcess::TYPE_WEB) {
            $this->toastError(__('The web unit is managed from Runtime — start command and port.'));

            return;
        }

        $name = $process->name;
        $unitName = app(SiteSystemdUnitBuilder::class)->processUnitName($this->site, $process);
        $process->delete();

        if ($this->siteSupportsSystemdServices()) {
            TearDownSiteSystemdUnitJob::dispatch($this->site->id, $unitName);
        }

        $this->site->load('processes');
        $this->toastSuccess(__('Unit :name removed.', ['name' => $name]));
    }

    public function toggleSiteProcessActive(string $id): void
    {
        $this->authorize('update', $this->site);

        $process = $this->site->processes()->whereKey($id)->first();
        if ($process === null) {
            return;
        }

        $next = ! (bool) $process->is_active;
        if ($process->type === SiteProcess::TYPE_WEB && ! $next) {
            $this->toastError(__('The web unit must stay active.'));

            return;
        }

        $process->update(['is_active' => $next]);

        $this->toastSuccess($next
            ? __('Unit :name reactivated.', ['name' => $process->name])
            : __('Unit :name deactivated.', ['name' => $process->name]),
        );
    }

    public function setSiteProcessScale(string $id, int $scale): void
    {
        $this->authorize('update', $this->site);

        if ($scale < 1 || $scale > 16) {
            $this->toastError(__('Scale must be between 1 and 16.'));

            return;
        }

        $process = $this->site->processes()->whereKey($id)->first();
        if ($process === null) {
            return;
        }

        $process->update(['scale' => $scale]);

        if ($this->siteSupportsSystemdServices()) {
            ProvisionSiteSystemdUnitsJob::dispatch($this->site->id, auth()->id());
        }

        $this->toastSuccess(__('Scale set to :n for :name.', [
            'n' => $scale,
            'name' => $process->name,
        ]));
    }

    public function restartSiteProcess(string $id): void
    {
        $this->authorize('update', $this->site);

        if (! $this->siteSupportsSystemdServices()) {
            $this->toastError(__('This site has no systemd units to restart.'));

            return;
        }

        $process = $this->site->processes()->whereKey($id)->first();
        if ($process === null) {
            return;
        }

        if ($process->type === SiteProcess::TYPE_WEB) {
            $this->toastError(__('Restart the web unit from a deploy or systemctl on the server.'));

            return;
        }

        $unitName = app(SiteSystemdUnitBuilder::class)->processUnitName($this->site, $process);

        try {
            app(SiteSystemdProvisioner::class)->restartUnit($this->site, $unitName);
        } catch (\Throwable $e) {
            $this->toastError(__('Restart failed: :msg', ['msg' => $e->getMessage()]));

            return;
        }

        $this->toastSuccess(__('Restarted :name.', ['name' => $process->name]));
    }

    public function syncSystemdUnits(): void
    {
        $this->authorize('update', $this->site);

        if (! $this->siteSupportsSystemdServices()) {
            $this->toastError(__('This site does not use systemd-managed services.'));

            return;
        }

        ProvisionSiteSystemdUnitsJob::dispatch($this->site->id, auth()->id());
        $this->toastSuccess(__('Systemd sync queued.'));
    }

    public function previewUnit(?string $processId = null): void
    {
        $this->authorize('view', $this->site);

        $builder = app(SiteSystemdUnitBuilder::class);
        $deployUser = $this->site->effectiveSystemUser($this->server);

        if ($processId === null || $processId === 'web') {
            $content = $builder->buildWebUnit($this->site, $deployUser);
            $this->preview_process_id = 'web';
            $this->unit_preview_body = $content ?? __('No web unit — PHP/static sites use FPM or nginx only. Set start command on Runtime → Overview for app servers.');
            $this->services_workspace_tab = 'preview';

            return;
        }

        $process = $this->site->processes()->whereKey($processId)->first();
        if ($process === null) {
            return;
        }

        $this->preview_process_id = $process->id;
        $content = $process->type === SiteProcess::TYPE_WEB
            ? $builder->buildWebUnit($this->site, $deployUser)
            : $builder->buildProcessUnit($this->site, $process, $deployUser);

        $this->unit_preview_body = $content ?? __('No unit file — command is not set yet.');
        $this->services_workspace_tab = 'preview';
    }

    protected function siteSupportsSystemdServices(): bool
    {
        return Site::supportsSystemdServices($this->site, $this->server);
    }

    /**
     * @return array<string, string> unit filename => rendered unit body
     */
    protected function buildAllUnitPreviews(): array
    {
        $builder = app(SiteSystemdUnitBuilder::class);
        $deployUser = $this->site->effectiveSystemUser($this->server);
        $units = [];

        $web = $builder->buildWebUnit($this->site, $deployUser);
        if ($web !== null) {
            $units[$builder->webUnitName($this->site)] = $web;
        }

        foreach ($this->site->processes as $process) {
            if ($process->type === SiteProcess::TYPE_WEB) {
                continue;
            }
            $unit = $builder->buildProcessUnit($this->site, $process, $deployUser);
            if ($unit !== null) {
                $units[$builder->processUnitName($this->site, $process)] = $unit;
            }
        }

        return $units;
    }
}
