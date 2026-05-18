<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Create;

use App\Actions\Servers\ResolveKubernetesClusters;
use App\Livewire\Forms\ServerCreateForm;
use App\Livewire\Servers\Concerns\InteractsWithServerCreateDraft;
use App\Livewire\Servers\Concerns\ServerCreateActions;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerCreateDraft;
use App\Services\AwsEksService;
use App\Services\Servers\ServerCreatePresetCatalog;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Step 3 of the create-server wizard. "What it runs":
 * install profile + server role + stack details (webserver / PHP / DB / cache).
 *
 * Auto-skipped (in mount()) for the custom + Docker host combination.
 */
#[Layout('layouts.app')]
class StepWhat extends Component
{
    use InteractsWithServerCreateDraft;
    use ServerCreateActions;

    public ServerCreateForm $form;

    /**
     * Slug of the preset that drove the current form values, when one
     * was applied. Empty when the user is hand-rolling the stack.
     */
    public string $selectedPreset = '';

    public function mount(): mixed
    {
        $this->authorize('create', Server::class);

        if ($redirect = $this->enforceDraftGate()) {
            return $redirect;
        }

        $this->hydrateFormFromDraft($this->form, $this->currentDraft());

        // Docker hosts skip the stack-shaped step entirely. K8s does NOT skip — it
        // re-uses this step for cluster + namespace selection (see render() below).
        $skipsStack = ($this->form->mode === 'custom' && $this->form->custom_host_kind === 'docker')
            || ($this->form->mode === 'provider' && $this->form->provider_host_kind === 'docker');
        if ($skipsStack) {
            $this->saveDraftFromForm($this->form, advanceTo: 4);

            return $this->redirect(route(self::routeNameForStep(4)), navigate: true);
        }

        $this->ensureDefaultEksRegion();
        $this->autoSelectSingletonKubernetesCluster();
        $this->ensureDefaultNewClusterName();

        return null;
    }

    /**
     * Seed do_kubernetes_aws_region from the credential's stored region the
     * first time the user lands on StepWhat with an AWS K8s draft, so the
     * region picker isn't blank. User can pick anything else; we don't clobber
     * once a value is set.
     */
    private function ensureDefaultEksRegion(): void
    {
        if ($this->form->type !== 'aws_kubernetes') {
            return;
        }
        if ($this->form->do_kubernetes_aws_region !== '') {
            return;
        }
        if ($this->form->provider_credential_id === '') {
            return;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }
        $credential = ProviderCredential::query()
            ->where('organization_id', $org->getKey())
            ->find($this->form->provider_credential_id);
        if ($credential === null) {
            return;
        }
        $defaultRegion = (string) ($credential->credentials['region'] ?? config('services.aws.default_region', 'us-east-1'));
        if ($defaultRegion === '') {
            return;
        }

        $this->form->do_kubernetes_aws_region = $defaultRegion;
        $this->saveDraftFromForm($this->form);
    }

    /**
     * Livewire hook: when the user changes the AWS region picker, drop the
     * memoized cluster list so the next kubernetesClusters() call re-fetches
     * against the new region.
     */
    public function updatedFormDoKubernetesAwsRegion(): void
    {
        $this->memoKubernetesClustersKey = null;
        $this->memoKubernetesClusters = null;
        // Picking a new region invalidates any previously-picked cluster (its
        // name might not exist in this region). Reset.
        $this->form->do_kubernetes_cluster_name = '';
    }

    /**
     * Livewire hook: when the user toggles the K8s source pill from "Use existing"
     * to "Create new", seed a default cluster name so the field isn't blank.
     */
    public function updatedFormDoKubernetesSource(string $value): void
    {
        if ($value === 'new') {
            $this->ensureDefaultNewClusterName();
        }
    }

    /**
     * Roll a new default cluster name. Wired to a button next to the input so
     * the operator can spin a different slug without typing.
     */
    public function regenerateNewClusterName(): void
    {
        $this->form->do_kubernetes_new_name = $this->generateDefaultClusterName();
        $this->saveDraftFromForm($this->form);
    }

    /**
     * Seed do_kubernetes_new_name with `dply-cluster-XXXXXX` when create-new is
     * active and the field hasn't been touched. No-op when the user already
     * typed something so we don't clobber their pick across page reloads.
     */
    private function ensureDefaultNewClusterName(): void
    {
        if ($this->form->type !== 'digitalocean_kubernetes') {
            return;
        }
        if ($this->form->do_kubernetes_source !== 'new') {
            return;
        }
        if ($this->form->do_kubernetes_new_name !== '') {
            return;
        }

        $this->form->do_kubernetes_new_name = $this->generateDefaultClusterName();
        $this->saveDraftFromForm($this->form);
    }

    /**
     * DOKS naming: lowercase letters/digits/hyphens, must start with a letter,
     * 3-63 chars. `dply-cluster-` prefix tags it as wizard-generated and gives
     * us 6 random hex chars for uniqueness (~16M combos — plenty for a single
     * account).
     */
    private function generateDefaultClusterName(): string
    {
        return 'dply-cluster-'.bin2hex(random_bytes(3));
    }

    public function previous(): mixed
    {
        $this->saveDraftFromForm($this->form);

        return $this->redirect(route(self::routeNameForStep(2)), navigate: true);
    }

    public function next(): mixed
    {
        $this->authorize('create', Server::class);

        // K8s hosts validate cluster + namespace; VM/Docker validate the stack.
        if ($this->form->mode === 'provider' && $this->form->provider_host_kind === 'kubernetes') {
            $rules = [
                'form.do_kubernetes_namespace' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/'],
            ];
            $attrs = [
                'form.do_kubernetes_namespace' => __('namespace'),
            ];
            if ($this->form->type === 'digitalocean_kubernetes' && $this->form->do_kubernetes_source === 'new') {
                $rules['form.do_kubernetes_new_name'] = ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z]([-a-z0-9]*[a-z0-9])?$/'];
                $rules['form.do_kubernetes_new_region'] = ['required', 'string'];
                $rules['form.do_kubernetes_new_node_size'] = ['required', 'string'];
                $rules['form.do_kubernetes_new_node_count'] = ['required', 'integer', 'min:1', 'max:20'];
                $attrs['form.do_kubernetes_new_name'] = __('cluster name');
                $attrs['form.do_kubernetes_new_region'] = __('region');
                $attrs['form.do_kubernetes_new_node_size'] = __('node size');
                $attrs['form.do_kubernetes_new_node_count'] = __('node count');
            } else {
                $rules['form.do_kubernetes_cluster_name'] = ['required', 'string', 'max:255'];
                $attrs['form.do_kubernetes_cluster_name'] = __('cluster');
                if ($this->form->type === 'aws_kubernetes') {
                    $rules['form.do_kubernetes_aws_region'] = ['required', 'string'];
                    $attrs['form.do_kubernetes_aws_region'] = __('AWS region');
                }
            }
            $this->validate($rules, attributes: $attrs);
        } else {
            $this->validate([
                'form.install_profile' => ['required', 'string'],
                'form.server_role' => ['required', 'string'],
                'form.webserver' => ['required', 'string'],
                'form.php_version' => ['required', 'string'],
                'form.database' => ['required', 'string'],
                'form.cache_service' => ['required', 'string'],
            ], attributes: [
                'form.install_profile' => __('install profile'),
                'form.server_role' => __('server role'),
                'form.webserver' => __('web server'),
                'form.php_version' => __('PHP version'),
                'form.database' => __('database'),
                'form.cache_service' => __('cache service'),
            ]);
        }

        $this->saveDraftFromForm($this->form, advanceTo: 4);

        return $this->redirect(route(self::routeNameForStep(4)), navigate: true);
    }

    protected function stepNumber(): int
    {
        return 3;
    }

    /**
     * Apply a preset from {@see ServerCreatePresetCatalog} to the form.
     *
     * Each preset bundles role / webserver / PHP version / database /
     * cache so the user gets a Forge-style "I'm a Laravel app" tile
     * rather than picking 6 fields one by one. Per the strategy memo:
     * "Preset row at the top pre-fills runtimes + role + db + cache +
     * web; users can override anything below."
     *
     * Custom is intentionally a no-op (clears selection without
     * changing form state) so it acts as the "I'll pick myself"
     * escape hatch from a previous preset choice.
     */
    public function applyPreset(string $presetId, ServerCreatePresetCatalog $catalog): void
    {
        $preset = $catalog->find($presetId);
        if ($preset === null) {
            return;
        }

        $this->selectedPreset = $presetId;

        if ($presetId === ServerCreatePresetCatalog::ID_CUSTOM) {
            return;
        }

        // Preset → form field mapping. The preset describes the FULL
        // stack — anything the preset omits is treated as "not installed"
        // so clicking Rails clears the stale PHP pin from a prior Laravel
        // selection, and clicking Static clears DB/cache. Operators can
        // re-add anything in the override panel below.
        $this->form->server_role = $preset['role'];
        $this->form->webserver = $preset['webserver'] ?? 'none';
        $this->form->php_version = $preset['php_version'] ?? 'none';
        $this->form->database = $preset['database'] ?? 'none';
        $this->form->cache_service = $preset['cache'] ?? 'none';

        $runtimes = $preset['runtimes'];
        $this->form->ruby_version = (string) ($runtimes['ruby'] ?? '');
        $this->form->node_version = (string) ($runtimes['node'] ?? '');
        $this->form->python_version = (string) ($runtimes['python'] ?? '');
        $this->form->go_version = (string) ($runtimes['go'] ?? '');

        // Persist the preset choice on the draft so re-entering the step
        // remembers the tile the user clicked. Stored under the existing
        // form->install_profile slot for now since the wizard's draft
        // schema already round-trips that field.
        $this->form->install_profile = match ($presetId) {
            ServerCreatePresetCatalog::ID_LARAVEL => 'laravel_app',
            ServerCreatePresetCatalog::ID_RAILS,
            ServerCreatePresetCatalog::ID_NEXTJS,
            ServerCreatePresetCatalog::ID_DJANGO => 'plain',
            ServerCreatePresetCatalog::ID_POLYGLOT => 'plain',
            ServerCreatePresetCatalog::ID_STATIC => 'static_app_host',
            ServerCreatePresetCatalog::ID_DATABASE => 'plain',
            default => $this->form->install_profile,
        };
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $context = $this->buildPreflightContext($org);
        $catalog = $context['catalog'];
        $isKubernetes = $this->form->mode === 'provider' && $this->form->provider_host_kind === 'kubernetes';

        return view('livewire.servers.create.step-what', [
            'totalSteps' => ServerCreateDraft::TOTAL_STEPS,
            'reachedStep' => $this->currentDraft()?->step ?? 3,
            'provisionOptions' => $context['provisionOptions'],
            'installProfiles' => config('server_provision_options.install_profiles', []),
            'serverPresets' => app(ServerCreatePresetCatalog::class)->all(),
            'selectedPreset' => $this->selectedPreset,
            'isKubernetes' => $isKubernetes,
            'kubernetesClusters' => $this->kubernetesClusters(),
            'kubernetesProvider' => $this->form->type,
            'kubernetesRegions' => is_array($catalog['regions'] ?? null) ? $catalog['regions'] : [],
            'kubernetesNodeSizes' => is_array($catalog['sizes'] ?? null) ? $catalog['sizes'] : [],
            'kubernetesVersions' => is_array($catalog['kubernetes_versions'] ?? null) ? $catalog['kubernetes_versions'] : [],
            'kubernetesAwsRegions' => array_map(static fn (string $r): array => [
                'value' => $r,
                'label' => $r,
            ], AwsEksService::SUPPORTED_REGIONS),
            'canContinue' => $this->canContinueToReview($isKubernetes),
        ]);
    }

    /**
     * Mirrors next()'s validation rules but without throwing, so the Continue
     * button can disable itself BEFORE the user clicks. Same field requirements
     * (cluster name in existing mode, full new-cluster spec in create mode,
     * stack fields for VM/Docker hosts) so the disabled state and the
     * post-click validator agree.
     */
    private function canContinueToReview(bool $isKubernetes): bool
    {
        if ($isKubernetes) {
            $namespaceOk = $this->form->do_kubernetes_namespace !== ''
                && preg_match('/^[a-z0-9]([-a-z0-9]*[a-z0-9])?$/', $this->form->do_kubernetes_namespace) === 1
                && strlen($this->form->do_kubernetes_namespace) <= 63;
            if (! $namespaceOk) {
                return false;
            }

            if ($this->form->type === 'digitalocean_kubernetes' && $this->form->do_kubernetes_source === 'new') {
                return $this->form->do_kubernetes_new_name !== ''
                    && preg_match('/^[a-z]([-a-z0-9]*[a-z0-9])?$/', $this->form->do_kubernetes_new_name) === 1
                    && strlen($this->form->do_kubernetes_new_name) >= 3
                    && $this->form->do_kubernetes_new_region !== ''
                    && $this->form->do_kubernetes_new_node_size !== ''
                    && $this->form->do_kubernetes_new_node_count >= 1
                    && $this->form->do_kubernetes_new_node_count <= 20;
            }

            // EKS register-existing additionally needs a region pick so the
            // store + poller know which AWS region to query.
            if ($this->form->type === 'aws_kubernetes' && $this->form->do_kubernetes_aws_region === '') {
                return false;
            }

            return $this->form->do_kubernetes_cluster_name !== '';
        }

        return $this->form->install_profile !== ''
            && $this->form->server_role !== ''
            && $this->form->webserver !== ''
            && $this->form->php_version !== ''
            && $this->form->database !== ''
            && $this->form->cache_service !== '';
    }

    /**
     * If the user has exactly one managed cluster in their account, pre-fill the
     * cluster name so the cost preview shows the exact estimate immediately and
     * the user only has to confirm the namespace. No-op when the form already
     * has a cluster picked (we don't want to clobber an explicit user choice).
     */
    private function autoSelectSingletonKubernetesCluster(): void
    {
        if ($this->form->do_kubernetes_cluster_name !== '') {
            return;
        }

        $clusters = $this->kubernetesClusters();
        if (count($clusters) !== 1) {
            return;
        }

        $this->form->do_kubernetes_cluster_name = $clusters[0]['name'];
        $this->saveDraftFromForm($this->form);
    }

    /**
     * Cache key for the cluster lookup memo. Encodes the inputs that change
     * the result so we re-fetch when the user swaps credentials or toggles
     * away from K8s, but not on every render() call within a request.
     */
    private ?string $memoKubernetesClustersKey = null;

    /** @var list<array{id: string, name: string, region: string}>|null */
    private ?array $memoKubernetesClusters = null;

    /**
     * Available DOKS clusters for the picked credential. Empty list when host_kind
     * is not kubernetes, no credential is picked, or the API returned nothing —
     * the blade renders an empty-state in all three cases. Memoized per (cred + mode)
     * so the mount-time autoSelect and the render-time list don't both fire.
     *
     * @return list<array{id: string, name: string, region: string}>
     */
    private function kubernetesClusters(): array
    {
        if ($this->form->mode !== 'provider' || $this->form->provider_host_kind !== 'kubernetes') {
            return [];
        }
        if ($this->form->provider_credential_id === '') {
            return [];
        }

        // Region is part of the memo key so the cluster list re-resolves
        // when the user changes the EKS region picker. DOKS ignores region
        // (it's account-scoped, not region-scoped).
        $regionForMemo = $this->form->type === 'aws_kubernetes'
            ? $this->form->do_kubernetes_aws_region
            : '';
        $memoKey = $this->form->provider_credential_id.'|'.$this->form->mode.'|'.$this->form->provider_host_kind.'|'.$regionForMemo;
        if ($this->memoKubernetesClustersKey === $memoKey && is_array($this->memoKubernetesClusters)) {
            return $this->memoKubernetesClusters;
        }

        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return [];
        }

        $credential = ProviderCredential::query()
            ->where('organization_id', $org->getKey())
            ->find($this->form->provider_credential_id);

        if ($credential === null) {
            return [];
        }

        $regionOverride = $this->form->type === 'aws_kubernetes' && $this->form->do_kubernetes_aws_region !== ''
            ? $this->form->do_kubernetes_aws_region
            : null;
        $clusters = ResolveKubernetesClusters::run($credential, $regionOverride);
        $this->memoKubernetesClustersKey = $memoKey;
        $this->memoKubernetesClusters = $clusters;

        return $clusters;
    }
}
