<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeDashboardBindingProvisioner;
use App\Modules\Edge\Support\EdgeEffectiveBindings;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Throwable;

/**
 * Bindings editor — interactive counterpart to the read-only runtime-bindings
 * panel. Repo wrangler.toml rows are read-only; dashboard rows live on edgeMeta
 * and merge at deploy time via {@see EdgeEffectiveBindings}.
 */
class Bindings extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;

    /** @var list<array{name: string, kind: string, value: string}> */
    public array $dashboard_bindings = [];

    public string $new_name = '';

    public string $new_kind = 'kv';

    public string $new_value = '';

    /** When true, `new_value` is a resource name to create rather than an existing identifier. */
    public bool $create_resource = false;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
        $this->refreshFromMeta();
    }

    private function refreshFromMeta(): void
    {
        $this->dashboard_bindings = array_map(
            static fn (array $row): array => [
                'name' => $row['name'],
                'kind' => $row['kind'],
                'value' => $row['value'],
            ],
            EdgeEffectiveBindings::dashboardOverrides($this->site),
        );
    }

    public function addBinding(EdgeDashboardBindingProvisioner $provisioner): void
    {
        $this->authorize('update', $this->site);

        $name = trim($this->new_name);
        $kind = trim($this->new_kind);
        $value = trim($this->new_value);

        if ($name === '') {
            $this->addError('new_name', __('Binding name is required.'));

            return;
        }
        // Worker bindings surface as `env.NAME`, so the name has to be a legal
        // JS identifier or the script fails to boot at the edge.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            $this->addError('new_name', __('Name must start with a letter or underscore and contain only letters, numbers, and underscores.'));

            return;
        }
        if (in_array($name, EdgeEffectiveBindings::RESERVED_NAMES, true)) {
            $this->addError('new_name', __('That name is reserved by the dply Edge runtime.'));

            return;
        }
        if (! in_array($kind, EdgeEffectiveBindings::KINDS, true)) {
            $this->addError('new_kind', __('Unknown binding type.'));

            return;
        }
        if ($value === '') {
            $this->addError('new_value', $this->create_resource
                ? __('Give the resource a name to create.')
                : __('Enter the resource identifier to attach.'));

            return;
        }
        foreach ($this->dashboard_bindings as $existing) {
            if ($existing['name'] === $name) {
                $this->addError('new_name', __('A binding with that name already exists.'));

                return;
            }
        }
        // A repo-declared binding of the same name would win at deploy time and
        // this row would be silently dropped — say so now instead.
        foreach ($this->repoBindings() as $repo) {
            if ($repo['name'] === $name) {
                $this->addError('new_name', __('wrangler.toml already declares that binding; the repo file wins.'));

                return;
            }
        }

        if ($this->create_resource) {
            try {
                $value = $provisioner->create($this->site, $kind, $value);
            } catch (Throwable $e) {
                $this->addError('new_value', __('Could not create the resource: :msg', ['msg' => $e->getMessage()]));

                return;
            }
        }

        $this->dashboard_bindings[] = ['name' => $name, 'kind' => $kind, 'value' => $value];
        $this->persist();

        $this->new_name = '';
        $this->new_value = '';
        $this->create_resource = false;
        $this->toastSuccess(__('Binding added — applied on the next deploy.'));
    }

    /**
     * Detaches the binding from the site. The underlying resource is left alone
     * (it may hold data or be shared). Deleting the resource is a separate act.
     */
    public function removeBinding(int $index): void
    {
        $this->authorize('update', $this->site);

        if (! isset($this->dashboard_bindings[$index])) {
            return;
        }
        array_splice($this->dashboard_bindings, $index, 1);
        $this->persist();
        $this->toastSuccess(__('Binding detached — the resource was left in place.'));
    }

    private function persist(): void
    {
        $previous = EdgeEffectiveBindings::dashboardOverrides($this->site);

        $this->site->mergeEdgeMeta(['bindings_overrides' => array_values($this->dashboard_bindings)]);
        $this->site->save();

        audit_log(
            $this->site->organization,
            auth()->user(),
            'site.edge.bindings.updated',
            $this->site,
            ['bindings_overrides' => $previous],
            ['bindings_overrides' => $this->dashboard_bindings],
        );

        $this->refreshFromMeta();
    }

    private function latestConfigDeployment(): ?EdgeDeployment
    {
        return EdgeDeployment::query()
            ->where('site_id', $this->site->id)
            ->where('status', EdgeDeployment::STATUS_LIVE)
            ->latest('id')
            ->first()
            ?: EdgeDeployment::query()
                ->where('site_id', $this->site->id)
                ->whereNotNull('repo_config')
                ->latest('id')
                ->first();
    }

    /**
     * @return list<array{name: string, kind: string, value: string, source: string}>
     */
    private function repoBindings(): array
    {
        return array_values(array_filter(
            EdgeEffectiveBindings::for($this->site, $this->latestConfigDeployment()),
            static fn (array $b): bool => $b['source'] === 'repo',
        ));
    }

    /**
     * Bindings are injected into the *per-deployment Worker script*, which only
     * exists for SSR sites and for static/hybrid sites that ship a
     * `middleware.ts`. A purely static site serves assets straight from R2 with
     * no Worker of its own, so a binding there would never be reachable. Say so
     * rather than let the user add a row that silently does nothing.
     */
    private function hasWorker(): bool
    {
        $runtimeMode = (string) ($this->site->edgeMeta()['runtime_mode'] ?? 'static');
        if ($runtimeMode === 'ssr') {
            return true;
        }

        $deployment = $this->latestConfigDeployment();
        $meta = is_array($deployment?->meta) ? $deployment->meta : [];
        $middleware = is_array($meta['middleware'] ?? null) ? $meta['middleware'] : [];

        return is_string($middleware['script_name'] ?? null) && trim($middleware['script_name']) !== '';
    }

    public function render(): View
    {
        return view('livewire.sites.edge.workspace.bindings', array_merge(
            EdgeSiteViewData::context($this->site, 'edge-bindings'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'repoBindings' => $this->repoBindings(),
                'kinds' => EdgeEffectiveBindings::KINDS,
                'hasWorker' => $this->hasWorker(),
            ],
        ));
    }
}
