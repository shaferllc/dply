@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-server-create-stepper :current="3" :reached="$reachedStep" :mode="$form->mode" :hostKind="$form->custom_host_kind" :providerHostKind="$form->provider_host_kind" />

    <form wire:submit.prevent="next" class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(20rem,1fr)]">
      <div class="space-y-6 min-w-0">
        {{-- Hero --}}
        @php
            if ($isKubernetes) {
                $heroTitle = __('Pick the cluster');
                $heroDescription = __('Choose an existing managed cluster from your cloud account and the default namespace dply should target.');
            } elseif ($isDedicatedServerPurpose ?? false) {
                $heroTitle = __('Confirm the stack');
                $heroDescription = __('You already chose a dedicated server purpose. Review what dply will install — no app templates needed.');
            } else {
                $heroTitle = __('What it runs');
                $heroDescription = __('Pick a stack template and dply fills in everything else. The underlying knobs are tucked below in case you want to override.');
            }
        @endphp
        <x-hero-card
            :eyebrow="__('Step :n of :total', ['n' => 3, 'total' => $totalSteps])"
            :title="$heroTitle"
            :description="$heroDescription"
        >
            <x-slot:leading>
                <x-icon-badge size="md">
                    @if ($isKubernetes)
                        <x-heroicon-o-server-stack class="h-6 w-6" aria-hidden="true" />
                    @else
                        <x-heroicon-o-puzzle-piece class="h-6 w-6" aria-hidden="true" />
                    @endif
                </x-icon-badge>
            </x-slot:leading>
        </x-hero-card>

        @if ($sizeRoleMismatch)
            <section class="dply-card overflow-hidden border-amber-200">
                <div class="flex items-start gap-3 border-b border-amber-200/80 bg-amber-50/60 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-800 ring-1 ring-amber-200">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Plan sizing') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $sizeRoleMismatch['label'] }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $sizeRoleMismatch['detail'] }}</p>
                    </div>
                </div>
                <div class="flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:gap-6 sm:p-7">
                    @if ($sizeRoleMismatch['suggested_size'] !== '')
                        <div class="min-w-0 flex-1">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss/70">{{ __('Suggested plan') }}</p>
                            <p class="mt-0.5 text-sm font-medium text-brand-ink">{{ $sizeRoleMismatch['suggested_label'] }}</p>
                        </div>
                        <div class="flex shrink-0 flex-col gap-2 sm:flex-row sm:items-center">
                            <button
                                type="button"
                                wire:click="applySuggestedPlanSize('{{ $sizeRoleMismatch['suggested_size'] }}')"
                                class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                            >
                                {{ __('Use suggested plan') }}
                            </button>
                            <a href="{{ $stepWhereRoute }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                {{ __('Change plan on Step 2') }}
                            </a>
                        </div>
                    @else
                        <a href="{{ $stepWhereRoute }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            {{ __('Pick a different plan') }}
                        </a>
                    @endif
                </div>
            </section>
        @endif

        @if ($isKubernetes)
            @php
                // DOKS supports both register-existing and create-new. EKS create
                // needs IAM/VPC/subnet plumbing we don't have yet, so AWS stays
                // existing-only and the toggle is hidden.
                $canCreateNew = $kubernetesProvider === 'digitalocean_kubernetes';
                $isCreatingNew = $canCreateNew && $form->do_kubernetes_source === 'new';
            @endphp

            {{-- K8s host: pick an existing cluster OR have dply create one. --}}
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Cluster') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $isCreatingNew ? __('Create a new Kubernetes cluster') : __('Pick a Kubernetes cluster') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $isCreatingNew
                            ? __('Dply will provision a new DOKS cluster in your DigitalOcean account on submit. Provisioning takes 5–10 minutes; the server will land in "provisioning" until it\'s ready.')
                            : __('Dply lists managed DOKS clusters from your DigitalOcean account. Pick one and the region is inherited.') }}</p>
                    </div>
                </div>
                <div class="space-y-5 p-6 sm:p-7">
                    @if ($canCreateNew)
                        {{-- Source toggle: use existing vs create new. --}}
                        <x-server-workspace-tablist :aria-label="__('Cluster source')" class="!mb-0">
                            <x-server-workspace-tab icon="heroicon-o-link" :active="! $isCreatingNew" wire:click="$set('form.do_kubernetes_source', 'existing')">
                                {{ __('Use existing cluster') }}
                            </x-server-workspace-tab>
                            <x-server-workspace-tab icon="heroicon-o-plus" :active="$isCreatingNew" wire:click="$set('form.do_kubernetes_source', 'new')">
                                {{ __('Create new') }}
                            </x-server-workspace-tab>
                        </x-server-workspace-tablist>
                    @endif

                    @if ($isCreatingNew)
                        {{-- Create-new form: name + region + droplet size + count + HA + version. --}}
                        <div class="space-y-5">
                            <div>
                                <x-input-label for="do_kubernetes_new_name" :value="__('Cluster name')" />
                                <div class="mt-2 flex gap-2">
                                    <x-text-input
                                        id="do_kubernetes_new_name"
                                        wire:model.live.debounce.500ms="form.do_kubernetes_new_name"
                                        type="text"
                                        class="block w-full font-mono text-sm"
                                        placeholder="dply-cluster-XXXXXX"
                                    />
                                    <button
                                        type="button"
                                        wire:click="regenerateNewClusterName"
                                        wire:loading.attr="disabled"
                                        wire:target="regenerateNewClusterName"
                                        title="{{ __('Roll a new random cluster name') }}"
                                        class="inline-flex h-[42px] shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 text-xs font-semibold text-brand-moss transition-colors hover:border-brand-sage hover:text-brand-sage disabled:cursor-wait disabled:opacity-60"
                                    >
                                        <x-heroicon-o-arrow-path wire:loading.remove wire:target="regenerateNewClusterName" class="h-4 w-4" />
                                        <x-spinner wire:loading wire:target="regenerateNewClusterName" size="sm" />
                                        {{ __('Regenerate') }}
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Lowercase letters, numbers, and hyphens. Must start with a letter (DOKS naming rules). Dply pre-filled one for you — edit or roll a new one.') }}</p>
                                <x-input-error :messages="$errors->get('form.do_kubernetes_new_name')" class="mt-2" />
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                @include('livewire.servers.create._kubernetes-region-picker', [
                                    'regions' => $kubernetesRegions,
                                ])
                                @php
                                    $versionOptions = collect($kubernetesVersions)->map(fn (array $v): array => [
                                        'id' => (string) ($v['value'] ?? ''),
                                        'label' => (string) ($v['label'] ?? ''),
                                    ])->all();
                                @endphp
                                @include('livewire.servers.create._rich-select', [
                                    'id' => 'do_kubernetes_new_version',
                                    'label' => __('Kubernetes version'),
                                    'field' => 'form.do_kubernetes_new_version',
                                    'value' => $form->do_kubernetes_new_version,
                                    'options' => $versionOptions,
                                    'errorKey' => 'form.do_kubernetes_new_version',
                                    'eyebrow' => __('Selected version'),
                                    'placeholder' => __('Latest stable (DigitalOcean default)'),
                                ])
                            </div>

                            <div class="grid gap-4 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
                                @include('livewire.servers.create._kubernetes-size-picker', [
                                    'sizes' => $kubernetesNodeSizes,
                                ])
                                <div>
                                    <x-input-label for="do_kubernetes_new_node_count" :value="__('Node count')" />
                                    <x-text-input
                                        id="do_kubernetes_new_node_count"
                                        wire:model.live.debounce.500ms="form.do_kubernetes_new_node_count"
                                        type="number"
                                        min="1"
                                        max="20"
                                        class="mt-2 block w-full text-sm"
                                    />
                                    <x-input-error :messages="$errors->get('form.do_kubernetes_new_node_count')" class="mt-2" />
                                </div>
                            </div>

                            <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4">
                                <input
                                    type="checkbox"
                                    wire:model.live="form.do_kubernetes_new_ha"
                                    class="mt-0.5 h-4 w-4 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                                />
                                <span class="flex-1">
                                    <span class="block text-sm font-semibold text-brand-ink">{{ __('Highly available control plane') }}</span>
                                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Adds $40/mo. DigitalOcean runs three control-plane replicas for resilience. Leave off for staging/dev.') }}</span>
                                </span>
                            </label>
                        </div>
                    @else
                        {{-- EKS register-existing: clusters are region-scoped, so
                             the user picks a region first. Changing the region
                             invalidates any prior cluster pick (handled in
                             StepWhat::updatedFormDoKubernetesAwsRegion). --}}
                        @if ($kubernetesProvider === 'aws_kubernetes')
                            @php
                                $awsRegionOptions = collect($kubernetesAwsRegions)->map(fn (array $r): array => [
                                    'id' => (string) ($r['value'] ?? ''),
                                    'label' => (string) ($r['label'] ?? ''),
                                ])->all();
                            @endphp
                            @include('livewire.servers.create._rich-select', [
                                'id' => 'do_kubernetes_aws_region',
                                'label' => __('AWS region'),
                                'field' => 'form.do_kubernetes_aws_region',
                                'value' => $form->do_kubernetes_aws_region,
                                'options' => $awsRegionOptions,
                                'errorKey' => 'form.do_kubernetes_aws_region',
                                'eyebrow' => __('Selected region'),
                                'placeholder' => __('Pick the region your cluster lives in'),
                            ])
                        @endif

                        @if ($kubernetesClusters === [])
                            @if ($kubernetesProvider === 'aws_kubernetes')
                                <div data-testid="no-kubernetes-clusters" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                    <p class="font-semibold">{{ __('No EKS clusters found in this region.') }}</p>
                                    <p class="mt-1">{{ __('Try a different region above, or create a cluster in this region first.') }}</p>
                                    <a href="https://console.aws.amazon.com/eks/home" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 font-semibold underline hover:text-amber-700">
                                        {{ __('Open AWS EKS console') }} →
                                    </a>
                                </div>
                            @else
                                <div data-testid="no-kubernetes-clusters" class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                                    <p class="font-semibold">{{ __('No managed clusters found in this account.') }}</p>
                                    <p class="mt-1">{{ __('Switch to "Create new" above to have dply provision one, or create one in the DigitalOcean console and come back. Your draft will still be waiting.') }}</p>
                                    <a href="https://cloud.digitalocean.com/kubernetes/clusters" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 font-semibold underline hover:text-amber-700">
                                        {{ __('Open DigitalOcean Kubernetes') }} →
                                    </a>
                                </div>
                            @endif
                        @else
                            @php
                                $clusterOptions = collect($kubernetesClusters)->map(fn (array $c): array => [
                                    'id' => (string) ($c['name'] ?? ''),
                                    'label' => (string) ($c['name'] ?? ''),
                                    'summary' => (string) ($c['region'] ?? ''),
                                ])->all();
                            @endphp
                            @include('livewire.servers.create._rich-select', [
                                'id' => 'do_kubernetes_cluster_name',
                                'label' => __('Cluster'),
                                'field' => 'form.do_kubernetes_cluster_name',
                                'value' => $form->do_kubernetes_cluster_name,
                                'options' => $clusterOptions,
                                'errorKey' => 'form.do_kubernetes_cluster_name',
                                'eyebrow' => __('Selected cluster'),
                                'placeholder' => __('Select a cluster'),
                            ])
                        @endif
                    @endif

                    <div>
                        <x-input-label for="do_kubernetes_namespace" :value="__('Default namespace')" />
                        <x-text-input
                            id="do_kubernetes_namespace"
                            wire:model.live.debounce.500ms="form.do_kubernetes_namespace"
                            type="text"
                            class="mt-2 block w-full font-mono text-sm"
                            placeholder="default"
                        />
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Used as the default for containers added to this server. You can override per container.') }}</p>
                        <x-input-error :messages="$errors->get('form.do_kubernetes_namespace')" class="mt-2" />
                    </div>
                </div>
            </section>
        @else
        @if ($isDedicatedServerPurpose ?? false)
            @include('livewire.servers.create._dedicated-server-stack', [
                'selectedServerRole' => $selectedServerRole,
                'form' => $form,
                'provisionOptions' => $provisionOptions,
                'stepWhereRoute' => $stepWhereRoute,
                'dedicatedCacheEngineOptions' => $dedicatedCacheEngineOptions ?? [],
                'operatorPublicIp' => $operatorPublicIp ?? null,
                'networkCidr' => $networkCidr ?? null,
            ])
        @else
        @php
            $selectedInstallProfile = collect($installProfiles)->firstWhere('id', $form->install_profile);
            $selectedServerRole = collect($provisionOptions['server_roles'] ?? [])->firstWhere('id', $form->server_role);
            $featuredPresets = collect($serverPresets)->where('featured', true);
            $otherPresets = collect($serverPresets)->where('featured', false);
            $hasOverrides = $selectedInstallProfile || $selectedServerRole;
        @endphp

        {{-- 1. THE CHOICE: stack template (was "preset"). --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-cube class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Stack') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Pick a stack template') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Each template pre-fills the package bundle, machine job, and stack details. Click to choose.') }}</p>
                </div>
            </div>
            <div class="relative space-y-5 p-6 sm:p-7">
                @if ($orgBlueprints->isNotEmpty())
                    <div class="space-y-3">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Your blueprints') }}</p>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Golden-server snapshots saved from ready VMs in your organization.') }}</p>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($orgBlueprints as $blueprint)
                                <button
                                    type="button"
                                    wire:click="applyBlueprint('{{ $blueprint['id'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="applyBlueprint"
                                    @class([
                                        'group relative flex flex-col items-start rounded-2xl border-2 p-5 text-left shadow-sm transition-all disabled:cursor-wait',
                                        'border-brand-violet bg-gradient-to-br from-violet-50 via-white to-white shadow-violet-100 ring-2 ring-violet-200 ring-offset-2 ring-offset-white' => $selectedBlueprintId === $blueprint['id'],
                                        'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-violet-300 hover:shadow-md' => $selectedBlueprintId !== $blueprint['id'],
                                    ])
                                >
                                    <span class="mb-2 inline-flex items-center gap-1 rounded-full bg-violet-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-violet-700 ring-1 ring-violet-200">{{ __('Blueprint') }}</span>
                                    <span class="text-sm font-semibold text-brand-ink">{{ $blueprint['name'] }}</span>
                                    <span class="mt-1 text-xs leading-5 text-brand-moss">{{ $blueprint['description'] }}</span>
                                    <span
                                        wire:loading
                                        wire:target="applyBlueprint('{{ $blueprint['id'] }}')"
                                        class="absolute inset-0 flex items-center justify-center rounded-2xl bg-white/70 text-xs font-semibold text-brand-moss"
                                    >{{ __('Applying…') }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($featuredPresets as $preset)
                        <button
                            type="button"
                            wire:click="applyPreset('{{ $preset['id'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="applyPreset"
                            @class([
                                'group relative flex flex-col items-start rounded-2xl border-2 p-5 text-left shadow-sm transition-all disabled:cursor-wait',
                                'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $selectedPreset === $preset['id'],
                                'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $selectedPreset !== $preset['id'],
                            ])
                        >
                            @if ($preset['id'] === 'polyglot')
                                <span class="mb-2 inline-flex items-center gap-1 rounded-full bg-brand-gold/15 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-gold ring-1 ring-brand-gold/30">{{ __('Differentiator') }}</span>
                            @endif
                            <span class="text-sm font-semibold text-brand-ink">{{ $preset['name'] }}</span>
                            <span class="mt-1 text-xs leading-5 text-brand-moss">{{ $preset['description'] }}</span>
                            @if ($selectedPreset === $preset['id'])
                                <span class="absolute right-3 top-3 inline-flex items-center gap-0.5 rounded-full bg-brand-sage px-2 py-0.5 text-[10px] font-semibold text-white shadow-sm">
                                    <x-heroicon-m-check class="h-3 w-3" />
                                    {{ __('Picked') }}
                                </span>
                            @endif
                            {{-- Per-tile spinner when this specific tile was clicked.
                                 wire:target accepts the method+arg signature so only
                                 the active tile lights up, not all of them. --}}
                            <span
                                wire:loading
                                wire:target="applyPreset('{{ $preset['id'] }}')"
                                class="absolute right-3 top-3 inline-flex items-center gap-1 rounded-full bg-brand-ink/85 px-2 py-0.5 text-[10px] font-semibold text-brand-cream shadow-sm"
                            >
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Applying…') }}
                            </span>
                        </button>
                    @endforeach
                </div>

                <details class="text-sm" @if ($selectedPreset !== '' && ! $featuredPresets->pluck('id')->contains($selectedPreset)) open @endif>
                    <summary class="cursor-pointer font-medium text-brand-moss transition-colors hover:text-brand-ink">{{ __('Other templates (Static / Database node / Custom)') }}</summary>
                    <div class="mt-3 grid gap-3 sm:grid-cols-3">
                        @foreach ($otherPresets as $preset)
                            <button
                                type="button"
                                wire:click="applyPreset('{{ $preset['id'] }}')"
                                wire:loading.attr="disabled"
                                wire:target="applyPreset"
                                @class([
                                    'relative flex flex-col items-start rounded-2xl border-2 p-4 text-left shadow-sm transition-all disabled:cursor-wait',
                                    'border-brand-sage bg-gradient-to-br from-brand-sage/15 via-brand-sage/5 to-white shadow-brand-sage/15 ring-2 ring-brand-sage/30 ring-offset-2 ring-offset-white' => $selectedPreset === $preset['id'],
                                    'border-brand-ink/10 bg-white hover:-translate-y-0.5 hover:border-brand-sage/40 hover:shadow-md' => $selectedPreset !== $preset['id'],
                                ])
                            >
                                <span class="text-sm font-semibold text-brand-ink">{{ $preset['name'] }}</span>
                                <span class="mt-1 text-xs leading-5 text-brand-moss">{{ $preset['description'] }}</span>
                                <span
                                    wire:loading
                                    wire:target="applyPreset('{{ $preset['id'] }}')"
                                    class="absolute right-2 top-2 inline-flex items-center gap-1 rounded-full bg-brand-ink/85 px-2 py-0.5 text-[10px] font-semibold text-brand-cream shadow-sm"
                                >
                                    <x-spinner variant="cream" size="sm" />
                                    {{ __('Applying…') }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                </details>

                {{-- Section-wide loading veil. Sits above the tile grid so the
                     form looks "frozen" while Livewire round-trips applyPreset.
                     Pointer-events on so accidental double-taps are absorbed. --}}
                <div
                    wire:loading
                    wire:target="applyPreset"
                    class="absolute inset-0 z-10 flex items-center justify-center rounded-2xl bg-white/55 backdrop-blur-[1px]"
                >
                    <span class="inline-flex items-center gap-2 rounded-full bg-brand-ink px-4 py-2 text-xs font-semibold text-brand-cream shadow-md shadow-brand-ink/15">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Filling in template…') }}
                    </span>
                </div>
            </div>
        </section>

        {{-- 2. WHAT THE TEMPLATE FILLED IN: read-at-a-glance summary chips.
             Only renders once the operator picks a template — otherwise the
             form's baked-in defaults (Laravel app / nginx / 8.3 / …) would
             masquerade as a chosen template before any click. --}}
        @if ($selectedPreset !== '')
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-heroicon-o-sparkles class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Filled in') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Template filled in') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Override below if needed') }}</p>
                    </div>
                </div>
                <div class="p-6 sm:p-7">
                    <div class="flex flex-wrap gap-1.5 text-xs">
                        @if ($selectedInstallProfile)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Bundle') }}</span>
                                <span class="font-medium text-brand-ink">{{ $selectedInstallProfile['label'] }}</span>
                            </span>
                        @endif
                        @if ($selectedServerRole)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Job') }}</span>
                                <span class="font-medium text-brand-ink">{{ $selectedServerRole['label'] }}</span>
                            </span>
                        @endif
                        @if ($form->webserver)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Web') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->webserver }}</span>
                            </span>
                        @endif
                        @if ($form->php_version)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('PHP') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->php_version }}</span>
                            </span>
                        @endif
                        @if ($form->database)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('DB') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->database }}</span>
                            </span>
                        @endif
                        @if ($form->cache_service)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Cache') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->cache_service }}</span>
                            </span>
                        @endif
                        @if ($form->ruby_version !== '')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Ruby') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->ruby_version }}</span>
                            </span>
                        @endif
                        @if ($form->node_version !== '')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Node') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->node_version }}</span>
                            </span>
                        @endif
                        @if ($form->python_version !== '')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Python') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->python_version }}</span>
                            </span>
                        @endif
                        @if ($form->go_version !== '')
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-1 ring-1 ring-brand-ink/10">
                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Go') }}</span>
                                <span class="font-medium text-brand-ink">{{ $form->go_version }}</span>
                            </span>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        {{-- 2b. OPERATING SYSTEM: only for provider-provisioned VMs (catalog-backed). --}}
        @if ($showOsImagePicker)
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Operating system') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Choose an OS image') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('The base image the VM boots from. Ubuntu LTS is the dply default — pick Debian or an older Ubuntu if your app needs it.') }}</p>
                </div>
            </div>
            <div class="p-6 sm:p-7">
                <div class="sm:max-w-md">
                    @include('livewire.servers.create._rich-select', [
                        'id' => 'os_image',
                        'label' => __('OS image'),
                        'field' => 'form.os_image',
                        'value' => $form->os_image,
                        'options' => $osImageOptions,
                        'errorKey' => 'form.os_image',
                        'eyebrow' => __('Image'),
                        'placeholder' => __('Choose an image'),
                    ])
                </div>
            </div>
        </section>
        @endif

        {{-- 3. POWER-USER OVERRIDES: collapsed by default. --}}
        <section class="dply-card overflow-hidden">
            <details
                x-data="{ open: @entangle('overridesPanelOpen').live }"
                x-bind:open="open"
                class="group"
            >
                <summary
                    x-on:click.prevent="open = ! open"
                    class="flex cursor-pointer list-none items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7"
                >
                    <x-icon-badge>
                        <x-heroicon-o-adjustments-horizontal class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Overrides') }}</p>
                        <div class="flex items-baseline justify-between gap-3">
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Override the template') }}</h3>
                            <x-heroicon-o-chevron-down class="h-4 w-4 shrink-0 text-brand-moss transition-transform group-open:rotate-180" />
                        </div>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Pick a different package bundle, change the machine\'s job, or swap individual stack components. Most setups don\'t need this.') }}</p>
                    </div>
                </summary>

                <div class="space-y-6 p-6 sm:p-7">
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('High-level controls') }}</p>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Profile bundles a default package set; role narrows what actually installs (web vs db node vs LB).') }}</p>
                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            @include('livewire.servers.create._rich-select', [
                                'id' => 'install_profile',
                                'label' => __('Package bundle (profile)'),
                                'field' => 'form.install_profile',
                                'value' => $form->install_profile,
                                'options' => collect($installProfiles)->map(fn ($p) => [
                                    'id' => (string) ($p['id'] ?? ''),
                                    'label' => (string) ($p['label'] ?? ''),
                                    'summary' => (string) ($p['summary'] ?? ''),
                                ])->all(),
                                'errorKey' => 'form.install_profile',
                                'eyebrow' => __('Bundle'),
                                'placeholder' => __('Choose a bundle'),
                            ])
                            @include('livewire.servers.create._rich-select', [
                                'id' => 'server_role',
                                'label' => __('Machine\'s job (role)'),
                                'field' => 'form.server_role',
                                'value' => $form->server_role,
                                'options' => collect($provisionOptions['server_roles'] ?? [])->map(fn ($r) => [
                                    'id' => (string) ($r['id'] ?? ''),
                                    'label' => (string) ($r['label'] ?? ''),
                                    'summary' => (string) ($r['summary'] ?? ''),
                                ])->all(),
                                'errorKey' => 'form.server_role',
                                'eyebrow' => __('Job'),
                                'placeholder' => __('Choose a job'),
                            ])
                        </div>
                    </div>

                    @php
                        // A field is "in scope" when the current template/role
                        // says it applies. We hide controls for things that
                        // won't be installed (e.g. PHP selector for a Rails
                        // stack) so the override panel only shows knobs for
                        // pieces of the actual stack. "none"/"" means the
                        // template explicitly opted out.
                        $showWebserver = $form->webserver !== '' && $form->webserver !== 'none';
                        $showPhp = $form->php_version !== '' && $form->php_version !== 'none';
                        $showDatabase = $form->database !== '' && $form->database !== 'none';
                        $showCache = $form->cache_service !== '' && $form->cache_service !== 'none';
                        $showRuby = $form->ruby_version !== '';
                        $showNode = $form->node_version !== '';
                        $showPython = $form->python_version !== '';
                        $showGo = $form->go_version !== '';
                        $hasComponents = $showWebserver || $showPhp || $showDatabase || $showCache;
                        $hasRuntimes = $showRuby || $showNode || $showPython || $showGo;
                    @endphp

                    @if ($hasComponents)
                        <div class="border-t border-brand-ink/10 pt-6">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Individual stack components') }}</p>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Swap a single piece (e.g. switch from Nginx to Caddy) without leaving the bundle.') }}</p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                @if ($showWebserver)
                                    @include('livewire.servers.create._rich-select', [
                                        'id' => 'webserver',
                                        'label' => __('Web server'),
                                        'field' => 'form.webserver',
                                        'value' => $form->webserver,
                                        'options' => $provisionOptions['webservers'] ?? [],
                                        'errorKey' => 'form.webserver',
                                    ])
                                @endif
                                @if ($showPhp)
                                    @include('livewire.servers.create._rich-select', [
                                        'id' => 'php_version',
                                        'label' => __('PHP version'),
                                        'field' => 'form.php_version',
                                        'value' => $form->php_version,
                                        'options' => $provisionOptions['php_versions'] ?? [],
                                        'errorKey' => 'form.php_version',
                                    ])
                                @endif
                                @if ($showDatabase)
                                    @include('livewire.servers.create._rich-select', [
                                        'id' => 'database',
                                        'label' => __('Database'),
                                        'field' => 'form.database',
                                        'value' => $form->database,
                                        'options' => $provisionOptions['databases'] ?? [],
                                        'errorKey' => 'form.database',
                                    ])
                                @endif
                                @if ($showCache)
                                    @include('livewire.servers.create._rich-select', [
                                        'id' => 'cache_service',
                                        'label' => __('Cache service'),
                                        'field' => 'form.cache_service',
                                        'value' => $form->cache_service,
                                        'options' => $provisionOptions['cache_services'] ?? [],
                                        'errorKey' => 'form.cache_service',
                                    ])
                                @endif
                            </div>
                        </div>
                    @endif

                    @if ($hasRuntimes)
                        <div class="border-t border-brand-ink/10 pt-6">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Language runtimes') }}</p>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Templates pre-fill these (Rails → Ruby, Next.js → Node, etc.); pick "Not installed" to drop one.') }}</p>
                            <div class="mt-3 grid gap-4 sm:grid-cols-2">
                                @if ($showRuby)
                                    @include('livewire.servers.create._rich-select', [
                                        'id' => 'ruby_version',
                                        'label' => __('Ruby'),
                                        'field' => 'form.ruby_version',
                                        'value' => $form->ruby_version,
                                        'options' => $provisionOptions['ruby_versions'] ?? [],
                                        'errorKey' => 'form.ruby_version',
                                    ])
                                @endif
                                @if ($showNode)
                                    @include('livewire.servers.create._rich-select', [
                                        'id' => 'node_version',
                                        'label' => __('Node.js'),
                                        'field' => 'form.node_version',
                                        'value' => $form->node_version,
                                        'options' => $provisionOptions['node_versions'] ?? [],
                                        'errorKey' => 'form.node_version',
                                    ])
                                @endif
                                @if ($showPython)
                                    @include('livewire.servers.create._rich-select', [
                                        'id' => 'python_version',
                                        'label' => __('Python'),
                                        'field' => 'form.python_version',
                                        'value' => $form->python_version,
                                        'options' => $provisionOptions['python_versions'] ?? [],
                                        'errorKey' => 'form.python_version',
                                    ])
                                @endif
                                @if ($showGo)
                                    @include('livewire.servers.create._rich-select', [
                                        'id' => 'go_version',
                                        'label' => __('Go'),
                                        'field' => 'form.go_version',
                                        'value' => $form->go_version,
                                        'options' => $provisionOptions['go_versions'] ?? [],
                                        'errorKey' => 'form.go_version',
                                    ])
                                @endif
                            </div>
                        </div>
                    @endif

                    @if (! $hasComponents && ! $hasRuntimes)
                        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-4 py-5 text-center text-xs text-brand-moss">
                            {{ __('No stack components selected. Pick a template above (or change the role) to see the swappable pieces here.') }}
                        </div>
                    @endif
                </div>
            </details>
        </section>
        @endif
        @endif

        {{-- Footer actions --}}
        @if (! $canContinue && filled($continueBlockerMessage ?? null))
            <div class="rounded-2xl border border-amber-200/80 bg-amber-50/80 px-5 py-4 text-sm leading-relaxed text-amber-950 ring-1 ring-amber-200/60">
                <span class="inline-flex items-start gap-2">
                    <x-heroicon-m-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0" aria-hidden="true" />
                    <span>{{ $continueBlockerMessage }}</span>
                </span>
            </div>
        @endif
        <footer class="flex flex-col-reverse items-stretch justify-between gap-3 rounded-2xl border border-brand-ink/10 bg-brand-sand/25 px-5 py-4 shadow-sm sm:flex-row sm:items-center">
            <button
                type="button"
                wire:click="openDiscardDraftModal"
                class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-transparent px-3 py-2 text-sm font-medium text-brand-moss transition-colors hover:bg-white hover:text-red-700"
            >
                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Discard draft') }}
            </button>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <button
                    type="button"
                    wire:click="previous"
                    class="inline-flex items-center justify-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Back') }}
                </button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="next"
                    @disabled(! $canContinue)
                    title="{{ ! $canContinue ? ($continueBlockerMessage ?? ($isKubernetes ? __('Pick or create a cluster (and confirm the namespace) before continuing.') : __('Fill in the stack template before continuing.'))) : '' }}"
                    class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:bg-slate-400 disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="next">{{ $canContinue ? __('Continue to review') : __('Fill required fields') }}</span>
                    <x-heroicon-o-arrow-right wire:loading.remove wire:target="next" class="h-4 w-4 shrink-0" aria-hidden="true" />
                    <span wire:loading wire:target="next" class="inline-flex items-center gap-2 whitespace-nowrap">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Saving…') }}
                    </span>
                </button>
            </div>
        </footer>
      </div>

      {{-- Sidebar: explain the new vocabulary so the user gets it. --}}
      <aside class="space-y-4 lg:sticky lg:top-24 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto lg:overscroll-contain lg:self-start">
        @if ($isKubernetes)
            <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">
                    <x-heroicon-m-academic-cap class="h-4 w-4" />
                    {{ __('Cluster + namespace') }}
                </p>
                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="font-semibold text-brand-ink">{{ __('Cluster') }}</dt>
                        <dd class="mt-0.5 text-xs leading-5 text-brand-moss">{{ __('The DOKS cluster you already have in DigitalOcean. Dply registers it as a server here so you can deploy containers into it.') }}</dd>
                    </div>
                    <div class="border-t border-brand-ink/10 pt-3">
                        <dt class="font-semibold text-brand-ink">{{ __('Namespace') }}</dt>
                        <dd class="mt-0.5 text-xs leading-5 text-brand-moss">{{ __('Default Kubernetes namespace for containers added to this server. You can override per container later.') }}</dd>
                    </div>
                </dl>
            </div>
        @else
            @if ($isDedicatedServerPurpose ?? false)
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                    <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">
                        <x-heroicon-m-academic-cap class="h-4 w-4" />
                        {{ __('Dedicated server') }}
                    </p>
                    <p class="mt-3 text-xs leading-5 text-brand-moss">
                        {{ __('Purpose was set on the previous step. dply installs only the packages that role needs — Redis, Valkey, a database engine, or similar — without asking you to pick an app framework template.') }}
                    </p>
                </div>
            @else
            <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">
                    <x-heroicon-m-academic-cap class="h-4 w-4" />
                    {{ __('How these fit together') }}
                </p>
                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="font-semibold text-brand-ink">{{ __('Template') }}</dt>
                        <dd class="mt-0.5 text-xs leading-5 text-brand-moss">{{ __('"I want to deploy a Laravel app." Sets the bundle + job + stack to a known-good combo for that use case.') }}</dd>
                    </div>
                    <div class="border-t border-brand-ink/10 pt-3">
                        <dt class="font-semibold text-brand-ink">{{ __('Package bundle (profile)') }}</dt>
                        <dd class="mt-0.5 text-xs leading-5 text-brand-moss">{{ __('Which set of system packages to install. Templates pick this for you; override only if you know your bundle differs from the defaults.') }}</dd>
                    </div>
                    <div class="border-t border-brand-ink/10 pt-3">
                        <dt class="font-semibold text-brand-ink">{{ __('Machine\'s job (role)') }}</dt>
                        <dd class="mt-0.5 text-xs leading-5 text-brand-moss">{{ __('What this server actually does in your fleet — application, worker, database node, cache, load balancer. Drives which packages from the bundle actually get installed.') }}</dd>
                    </div>
                </dl>
            </div>
            @endif

            @if ($selectedServerRole && ! empty($selectedServerRole['installs']) && is_array($selectedServerRole['installs']))
                <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.22em] text-brand-sage">{{ __('Will install') }}</p>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('From your current job choice') }}</p>
                    <ul class="mt-2 space-y-1 text-xs text-brand-moss">
                        @foreach (array_slice($selectedServerRole['installs'], 0, 6) as $item)
                            <li class="inline-flex items-start gap-1.5"><x-heroicon-m-check-circle class="mt-0.5 h-3 w-3 text-brand-sage" />{{ is_array($item) ? ($item['label'] ?? '') : $item }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        @endif
      </aside>
    </form>

    @include('livewire.servers.create._discard-draft-modal')
</div>
