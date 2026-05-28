<div
    class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8"
    x-data="{ view: 'form' }"
    x-init="$watch('view', v => { if (v === 'canvas') $nextTick(() => window.dispatchEvent(new CustomEvent('canvas-shown'))) })"
>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Cloud apps'), 'href' => route('cloud.index'), 'icon' => 'cloud'],
        ['label' => __('Deploy'), 'icon' => 'rocket-launch'],
    ]" />

    <header class="relative mt-6 overflow-hidden rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream via-white to-brand-sand/25 px-6 py-8 shadow-sm sm:px-10 sm:py-10 dark:border-brand-mist/20 dark:from-zinc-900 dark:via-zinc-900 dark:to-brand-sand/10">
        <div class="pointer-events-none absolute -end-16 -top-16 h-56 w-56 rounded-full bg-brand-sage/15 blur-3xl dark:bg-brand-sage/10" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-20 -start-12 h-48 w-48 rounded-full bg-brand-gold/15 blur-3xl dark:bg-brand-gold/10" aria-hidden="true"></div>
        <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-2xl">
                <div class="inline-flex items-center gap-2 rounded-full border border-brand-sage/25 bg-brand-sage/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-forest dark:border-brand-sage/30 dark:bg-brand-sage/15 dark:text-brand-sage">
                    <x-heroicon-o-cloud class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('dply Cloud') }}
                </div>
                <h1 class="mt-4 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">{{ __('Deploy an app') }}</h1>
                <p class="mt-3 max-w-prose text-sm leading-relaxed text-brand-moss sm:text-base">
                    {{ __('Point dply at a GitHub repository or a pre-built image. We build, ship, and run it on your cloud account — managed TLS, autoscaling, and zero-config health checks.') }}
                </p>
            </div>
            <div class="flex shrink-0 flex-col items-stretch gap-3 sm:items-end">
                <div role="tablist" aria-label="{{ __('View mode') }}" class="inline-flex self-end rounded-xl border border-brand-ink/10 bg-white/90 p-1 shadow-sm dark:border-brand-mist/25 dark:bg-zinc-800/80">
                    <button
                        type="button"
                        role="tab"
                        x-on:click="view = 'form'"
                        x-bind:aria-selected="view === 'form'"
                        x-bind:class="view === 'form' ? 'bg-brand-ink text-brand-cream shadow-sm' : 'text-brand-moss hover:text-brand-ink'"
                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition"
                        title="{{ __('Form view') }}"
                    >
                        <x-heroicon-o-bars-3-bottom-left class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Form') }}
                    </button>
                    <button
                        type="button"
                        role="tab"
                        x-on:click="view = 'canvas'"
                        x-bind:aria-selected="view === 'canvas'"
                        x-bind:class="view === 'canvas' ? 'bg-brand-ink text-brand-cream shadow-sm' : 'text-brand-moss hover:text-brand-ink'"
                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold transition"
                        title="{{ __('Canvas view') }}"
                    >
                        <x-heroicon-o-squares-2x2 class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Canvas') }}
                    </button>
                </div>
                <div class="flex flex-wrap gap-3 text-xs text-brand-moss">
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white/80 px-3 py-1.5 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                        <x-heroicon-o-lock-closed class="h-3.5 w-3.5 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                        {{ __('Auto TLS') }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white/80 px-3 py-1.5 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                        <x-heroicon-o-arrows-up-down class="h-3.5 w-3.5 text-brand-sage" aria-hidden="true" />
                        {{ __('Autoscaling') }}
                    </span>
                    <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white/80 px-3 py-1.5 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                        <x-heroicon-o-cloud-arrow-up class="h-3.5 w-3.5 text-brand-gold" aria-hidden="true" />
                        {{ __('Deploy on push') }}
                    </span>
                </div>
            </div>
        </div>
    </header>

    @if ($connectedBackends->isEmpty() && ! $fakeCloudActive)
        <section class="dply-card overflow-hidden border-amber-200 mt-6">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                            <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Connect a cloud account to deploy') }}</h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('dply needs a DigitalOcean or AWS account to run your app on. Connect once and we handle the rest.') }}</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2 text-xs">
                        <a href="{{ route('credentials.index', ['provider' => 'digitalocean']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 font-semibold text-brand-cream hover:bg-brand-ink/90">
                            {{ __('Connect DigitalOcean') }}
                        </a>
                        <a href="{{ route('credentials.index', ['provider' => 'aws_app_runner']) }}" wire:navigate class="font-medium text-brand-moss hover:text-brand-ink">{{ __('Use AWS instead') }}</a>
                    </div>
                </div>
            </div>
        </section>
    @elseif ($connectedBackends->isEmpty() && $fakeCloudActive)
        <div data-testid="fake-cloud-active-notice" class="mt-6 flex gap-3 rounded-2xl border border-sky-200/80 bg-sky-50/70 px-5 py-4 text-sm text-sky-950 dark:border-sky-900/40 dark:bg-sky-950/30 dark:text-sky-200">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300" aria-hidden="true">
                <x-heroicon-o-beaker class="h-5 w-5" />
            </span>
            <div>
                <p class="font-semibold">{{ __('Sandbox mode is on — no real cloud account needed') }}</p>
                <p class="mt-1 text-sky-900/80 dark:text-sky-200/80">{{ __('Deployments land on a local sandbox so you can explore the flow. Live URLs are synthetic until you connect a real cloud account.') }}</p>
            </div>
        </div>
    @endif

    <form wire:submit="deploy" class="mt-8">
        <div x-show="view === 'form'" class="grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] lg:items-start">
        <div class="min-w-0 space-y-6">
            {{-- 01 Source --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">01</span>
                    <div class="min-w-0 flex-1 space-y-5">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Source') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Deploy from a Git repository or a pre-built image. Source mode rebuilds on every push.') }}</p>
                        </div>

                        <div role="tablist" aria-label="{{ __('Deployment source') }}" class="inline-flex w-full max-w-md rounded-xl border border-brand-ink/10 bg-brand-cream/60 p-1 text-sm dark:border-brand-mist/20 dark:bg-zinc-800/60">
                            <button type="button" role="tab" aria-selected="{{ $mode === 'source' ? 'true' : 'false' }}" wire:click="$set('mode', 'source')"
                                @class([
                                    'flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 font-medium transition',
                                    'bg-brand-ink text-brand-cream shadow-sm' => $mode === 'source',
                                    'text-brand-moss hover:text-brand-ink' => $mode !== 'source',
                                ])>
                                <x-heroicon-o-code-bracket class="h-4 w-4" aria-hidden="true" />
                                {{ __('From repository') }}
                            </button>
                            <button type="button" role="tab" aria-selected="{{ $mode === 'image' ? 'true' : 'false' }}" wire:click="$set('mode', 'image')"
                                @class([
                                    'flex-1 inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2 font-medium transition',
                                    'bg-brand-ink text-brand-cream shadow-sm' => $mode === 'image',
                                    'text-brand-moss hover:text-brand-ink' => $mode !== 'image',
                                ])>
                                <x-heroicon-o-cube class="h-4 w-4" aria-hidden="true" />
                                {{ __('From image') }}
                            </button>
                        </div>

                        <div>
                            <x-input-label for="name" :value="__('App name')" />
                            <x-text-input id="name" wire:model="name" type="text" class="mt-1 block w-full" required placeholder="acme-api" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Used in the Cloud index, app workspace, and default subdomain.') }}</p>
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>

                        @if ($mode === 'source')
                            @if ($backend === 'aws_app_runner' && ! $awsSourceReady)
                                <div data-testid="aws-github-connection-missing" class="flex items-start gap-3 rounded-xl border border-brand-gold/30 bg-brand-gold/10 p-4 text-sm text-brand-ink">
                                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-brand-rust" aria-hidden="true" />
                                    <div class="space-y-1">
                                        <p class="font-semibold">{{ __('Repository builds need GitHub authorized on your cloud account') }}</p>
                                        <p class="text-brand-moss">{{ __('Source-mode deploys to your connected cloud account require a GitHub connection that\'s missing right now. You can still deploy from a pre-built image, or finish the connection in your cloud account settings.') }}</p>
                                    </div>
                                </div>
                            @endif

                            <div class="flex flex-wrap items-center gap-3">
                                @if ($linkedSourceControlAccounts !== [])
                                    <div role="radiogroup" aria-label="{{ __('Where to find the repo') }}" class="inline-flex rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-1 text-xs dark:border-brand-mist/20 dark:bg-zinc-800/60">
                                        <button type="button" role="radio" aria-checked="{{ $repo_source === 'connected' ? 'true' : 'false' }}" wire:click="$set('repo_source', 'connected')"
                                            @class([
                                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-semibold transition',
                                                'bg-white text-brand-ink shadow-sm dark:bg-zinc-700' => $repo_source === 'connected',
                                                'text-brand-moss hover:text-brand-ink' => $repo_source !== 'connected',
                                            ])>
                                            <x-heroicon-m-link class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Pick from connected account') }}
                                        </button>
                                        <button type="button" role="radio" aria-checked="{{ $repo_source === 'manual' ? 'true' : 'false' }}" wire:click="$set('repo_source', 'manual')"
                                            @class([
                                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-semibold transition',
                                                'bg-white text-brand-ink shadow-sm dark:bg-zinc-700' => $repo_source === 'manual',
                                                'text-brand-moss hover:text-brand-ink' => $repo_source !== 'manual',
                                            ])>
                                            <x-heroicon-m-pencil-square class="h-3.5 w-3.5" aria-hidden="true" />
                                            {{ __('Enter manually') }}
                                        </button>
                                    </div>
                                @endif
                                <x-connect-provider-link>{{ __('Connect a provider') }} &rarr;</x-connect-provider-link>
                            </div>

                            @if ($repo_source === 'connected' && $linkedSourceControlAccounts !== [])
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="source_control_account_id" :value="__('Account')" />
                                        <select id="source_control_account_id" wire:model.live="source_control_account_id" class="dply-input mt-1 block w-full">
                                            @foreach ($linkedSourceControlAccounts as $account)
                                                <option value="{{ $account['id'] }}">{{ $account['label'] ?? $account['name'] ?? $account['id'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <x-input-label for="repository_selection" :value="__('Repository')" />
                                        <select id="repository_selection" wire:model.live="repository_selection" class="dply-input mt-1 block w-full" required>
                                            <option value="">{{ __('Select a repository…') }}</option>
                                            @foreach ($availableRepositories as $r)
                                                <option value="{{ $r['url'] }}">{{ $r['name'] ?? $r['url'] }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                </div>
                                @if ($repo !== '')
                                    <p class="text-xs text-brand-mist">{{ __('Will deploy :repo on branch :branch.', ['repo' => $repo, 'branch' => $branch]) }}</p>
                                @endif
                                <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                            @else
                                <div>
                                    <x-input-label for="repo" :value="__('GitHub repo')" />
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                                            <x-heroicon-o-code-bracket class="h-4 w-4" />
                                        </span>
                                        <x-text-input id="repo" wire:model.blur="repo" type="text" class="block w-full ps-10 font-mono text-sm" required placeholder="acme/api" />
                                    </div>
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('owner/name or full GitHub URL. dply pulls and builds it for you.') }}</p>
                                    <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                                </div>
                            @endif

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="branch" :value="__('Branch')" />
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                                            <x-heroicon-o-arrow-trending-up class="h-4 w-4" />
                                        </span>
                                        <x-text-input id="branch" wire:model="branch" type="text" class="block w-full ps-10 font-mono text-sm" required placeholder="main" />
                                    </div>
                                    <x-input-error :messages="$errors->get('branch')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="dockerfile_path" :value="__('Dockerfile path (optional)')" />
                                    <x-text-input id="dockerfile_path" wire:model="dockerfile_path" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="Dockerfile" />
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('Leave blank for buildpack auto-detection.') }}</p>
                                </div>
                            </div>

                            <label class="group relative flex cursor-pointer rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4 transition-colors has-[:checked]:border-brand-sage/40 has-[:checked]:bg-brand-sage/5 hover:border-brand-sage/30 dark:border-brand-mist/20 dark:bg-zinc-800/40 dark:has-[:checked]:border-brand-sage/35 dark:has-[:checked]:bg-brand-sage/10">
                                <input type="checkbox" wire:model="deploy_on_push" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40 dark:border-brand-mist/30" />
                                <span class="ms-3">
                                    <span class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                        <x-heroicon-o-bolt class="h-4 w-4 text-brand-gold" aria-hidden="true" />
                                        {{ __('Auto-deploy on push to this branch') }}
                                    </span>
                                    <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('A GitHub webhook triggers a rebuild and rollout every time you push.') }}</span>
                                </span>
                            </label>
                        @else
                            <div>
                                <x-input-label for="image" :value="__('Image')" />
                                <div class="relative mt-1">
                                    <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                                        <x-heroicon-o-cube class="h-4 w-4" />
                                    </span>
                                    <x-text-input id="image" wire:model="image" type="text" class="block w-full ps-10 font-mono text-sm" required placeholder="ghcr.io/acme/api:v1.2.3" />
                                </div>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Public registry images work out of the box. For private images, connect a registry credential first.') }}</p>
                                <x-input-error :messages="$errors->get('image')" class="mt-2" />
                            </div>
                        @endif
                    </div>
                </div>
            </section>

            @if ($mode === 'source')
                {{-- 02 Detect --}}
                <section class="rounded-2xl border-2 border-brand-sage/20 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-sage/25 dark:bg-zinc-900">
                    <div class="flex items-start gap-4">
                        <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">02</span>
                        <div class="min-w-0 flex-1 space-y-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Detect build settings') }}</h2>
                                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Preview what dply detects in this repo before you deploy. Buildpacks run automatically when no Dockerfile path is set.') }}</p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="detectFromRepository"
                                    wire:loading.attr="disabled"
                                    wire:target="detectFromRepository"
                                    class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:cursor-wait disabled:opacity-60 dark:shadow-none"
                                >
                                    <x-heroicon-o-sparkles wire:loading.remove wire:target="detectFromRepository" class="h-4 w-4" aria-hidden="true" />
                                    <x-spinner wire:loading wire:target="detectFromRepository" size="sm" variant="cream" />
                                    <span wire:loading.remove wire:target="detectFromRepository">{{ __('Detect runtime') }}</span>
                                    <span wire:loading wire:target="detectFromRepository">{{ __('Detecting…') }}</span>
                                </button>
                            </div>
                            <div class="rounded-xl border border-brand-ink/8 bg-brand-cream/40 p-4 dark:border-brand-mist/15 dark:bg-zinc-800/50">
                                @include('livewire.partials._runtime-detection-panel')
                            </div>
                        </div>
                    </div>
                </section>
            @endif

            {{-- Runtime --}}
            @php $runtimeStep = $mode === 'source' ? '03' : '02'; @endphp
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-gold/15 text-sm font-bold text-brand-olive ring-1 ring-brand-gold/25 dark:bg-brand-gold/10 dark:text-brand-gold dark:ring-brand-gold/20">{{ $runtimeStep }}</span>
                    <div class="min-w-0 flex-1 space-y-5">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Runtime') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('How the container runs — HTTP port, instance count, size, and region.') }}</p>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <div>
                                <x-input-label for="port" :value="__('HTTP port')" />
                                <x-text-input id="port" wire:model="port" type="number" min="1" max="65535" class="mt-1 block w-full font-mono" required />
                                <x-input-error :messages="$errors->get('port')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="instances" :value="__('Instances')" />
                                <x-text-input id="instances" wire:model="instances" type="number" min="1" max="50" class="mt-1 block w-full font-mono" required />
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Adjust later from the app dashboard.') }}</p>
                                <x-input-error :messages="$errors->get('instances')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="size_tier" :value="__('Size')" />
                                <select id="size_tier" wire:model.live="size_tier" class="dply-input mt-1 block w-full" required>
                                    <optgroup label="{{ __('Basic — fixed instances, no autoscaling') }}">
                                        <option value="small">{{ __('Small — light traffic, low memory') }}</option>
                                        <option value="medium">{{ __('Medium — typical web apps') }}</option>
                                        <option value="large">{{ __('Large — busy production traffic') }}</option>
                                        <option value="xlarge">{{ __('XLarge — heavyweight workloads') }}</option>
                                    </optgroup>
                                    <optgroup label="{{ __('Pro — dedicated CPU, autoscaling-ready') }}">
                                        <option value="small-pro">{{ __('Small Pro') }}</option>
                                        <option value="medium-pro">{{ __('Medium Pro') }}</option>
                                        <option value="large-pro">{{ __('Large Pro') }}</option>
                                        <option value="xlarge-pro">{{ __('XLarge Pro') }}</option>
                                    </optgroup>
                                </select>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Pro tiers cost more but unlock CPU-target autoscaling.') }}</p>
                                <x-input-error :messages="$errors->get('size_tier')" class="mt-2" />
                            </div>
                        </div>
                        <div>
                            <x-input-label for="region" :value="__('Region')" />
                            <select id="region" wire:model="region" class="dply-input mt-1 block w-full" required>
                                @foreach ($regions as $r)
                                    <option value="{{ $r['slug'] }}">{{ $r['label'] }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('region')" class="mt-2" />
                        </div>
                    </div>
                </div>
            </section>

            {{-- Environment --}}
            @php $envStep = $mode === 'source' ? '04' : '03'; @endphp
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">{{ $envStep }}</span>
                    <div class="min-w-0 flex-1 space-y-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-brand-ink">{{ __('Environment') }}</h2>
                                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Plain .env-style key/value pairs merged into the container at boot.') }}</p>
                            </div>
                            <span class="rounded-full bg-brand-cream/60 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-mist dark:bg-zinc-800">{{ __('Optional') }}</span>
                        </div>
                        <textarea id="env_file_content" wire:model="env_file_content" rows="6" class="block w-full rounded-lg border-brand-ink/15 bg-brand-cream/30 font-mono text-sm shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-800/60" placeholder="APP_ENV=production&#10;LOG_LEVEL=info"></textarea>
                        <p class="text-xs text-brand-mist">{{ __('One KEY=value per line. Lines starting with # are ignored.') }}</p>
                    </div>
                </div>
            </section>

            {{-- Configure (optional) divider --}}
            <div class="flex items-center gap-3 pt-2">
                <div class="h-px flex-1 bg-brand-ink/10 dark:bg-brand-mist/15"></div>
                <span class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Configure (optional)') }}</span>
                <div class="h-px flex-1 bg-brand-ink/10 dark:bg-brand-mist/15"></div>
            </div>

            {{-- Databases — one row per attached/created managed database.
                 Add buttons at the bottom mirror the canvas palette tiles.
                 Each row's env_prefix governs the connection env-var names
                 (DB_HOST, DB_2_HOST, REDIS_HOST, …) so multiple databases
                 of the same engine don't collide. --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-ink ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-cream">
                        <x-heroicon-o-circle-stack class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-4">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Databases') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Attach managed databases — each row gets its own connection env vars under the chosen prefix.') }}</p>
                        </div>

                        @if ($databases !== [])
                            <div class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/20">
                                @foreach ($databases as $i => $db)
                                    @php
                                        $rowMode = (string) ($db['mode'] ?? 'create');
                                    @endphp
                                    <div class="space-y-3 p-4">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                                {{ ucfirst((string) ($db['engine'] ?? 'postgres')) }}
                                                <span class="font-normal text-brand-mist">·</span>
                                                <span class="font-mono normal-case">{{ strtoupper((string) ($db['env_prefix'] ?? 'DB')) }}_*</span>
                                            </p>
                                            <button type="button" wire:click="removeDatabase({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                        </div>
                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div>
                                                <x-input-label :value="__('Name')" />
                                                <input type="text" wire:model.blur="databases.{{ $i }}.name" class="dply-input mt-1 block w-full font-mono text-sm" placeholder="postgres-1">
                                                <x-input-error :messages="$errors->get('databases.'.$i.'.name')" class="mt-2" />
                                            </div>
                                            <div>
                                                <x-input-label :value="__('Env prefix')" />
                                                <input type="text" wire:model.blur="databases.{{ $i }}.env_prefix" class="dply-input mt-1 block w-full font-mono text-sm" placeholder="DB">
                                                <x-input-error :messages="$errors->get('databases.'.$i.'.env_prefix')" class="mt-2" />
                                            </div>
                                            <div>
                                                <x-input-label :value="__('Mode')" />
                                                <select wire:model.live="databases.{{ $i }}.mode" class="dply-input mt-1 block w-full text-sm">
                                                    <option value="create">{{ __('Create new') }}</option>
                                                    <option value="attach" @disabled($attachableDatabases->isEmpty())>{{ __('Attach existing') }}</option>
                                                </select>
                                            </div>
                                            @if ($rowMode === 'attach')
                                                <div>
                                                    <x-input-label :value="__('Database')" />
                                                    <select wire:model="databases.{{ $i }}.cloud_database_id" class="dply-input mt-1 block w-full text-sm">
                                                        <option value="">{{ __('— select —') }}</option>
                                                        @foreach ($attachableDatabases as $existing)
                                                            <option value="{{ $existing->id }}">{{ $existing->name }} · {{ $existing->engine }} @if ($existing->status !== 'active')({{ $existing->status }})@endif</option>
                                                        @endforeach
                                                    </select>
                                                    <x-input-error :messages="$errors->get('databases.'.$i.'.cloud_database_id')" class="mt-2" />
                                                </div>
                                            @else
                                                <div>
                                                    <x-input-label :value="__('Engine')" />
                                                    <select wire:model.live="databases.{{ $i }}.engine" class="dply-input mt-1 block w-full text-sm">
                                                        <option value="postgres">Postgres</option>
                                                        <option value="mysql">MySQL</option>
                                                        <option value="redis">Redis</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <x-input-label :value="__('Size')" />
                                                    <select wire:model="databases.{{ $i }}.size" class="dply-input mt-1 block w-full text-sm">
                                                        <option value="small">small (1 vCPU / 1 GB)</option>
                                                        <option value="medium">medium (1 vCPU / 2 GB)</option>
                                                        <option value="large">large (2 vCPU / 4 GB)</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <x-input-label :value="__('Version')" />
                                                    <input type="text" wire:model.blur="databases.{{ $i }}.version" class="dply-input mt-1 block w-full font-mono text-sm" placeholder="17">
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-4 py-3 text-xs text-brand-moss dark:border-brand-mist/20 dark:bg-zinc-800/40">
                                {{ __('No databases attached. Add one below.') }}
                            </p>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="addDatabase('postgres')" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream">
                                <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Add Postgres') }}
                            </button>
                            <button type="button" wire:click="addDatabase('mysql')" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream">
                                <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Add MySQL') }}
                            </button>
                            <button type="button" wire:click="addDatabase('redis')" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream">
                                <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Add Redis') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Buckets — object-storage attachments. Records are created
                 in 'pending' status on deploy; real provider provisioning
                 (DO Spaces / S3 / R2) lands in a follow-up PR. --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-ink ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-cream">
                        <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-4">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Buckets') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Object storage — each row\'s env prefix governs the S3 connection env vars (e.g. S3_BUCKET, S3_2_BUCKET).') }}</p>
                        </div>

                        @if ($buckets !== [])
                            <div class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/20">
                                @foreach ($buckets as $i => $bk)
                                    <div class="space-y-3 p-4">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                                {{ __('Bucket') }}
                                                <span class="font-normal text-brand-mist">·</span>
                                                <span class="font-mono normal-case">{{ strtoupper((string) ($bk['env_prefix'] ?? 'S3')) }}_*</span>
                                            </p>
                                            <button type="button" wire:click="removeBucket({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                        </div>
                                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                            <div>
                                                <x-input-label :value="__('Name')" />
                                                <input type="text" wire:model.blur="buckets.{{ $i }}.name" class="dply-input mt-1 block w-full font-mono text-sm" placeholder="bucket-1">
                                                <x-input-error :messages="$errors->get('buckets.'.$i.'.name')" class="mt-2" />
                                            </div>
                                            <div>
                                                <x-input-label :value="__('Env prefix')" />
                                                <input type="text" wire:model.blur="buckets.{{ $i }}.env_prefix" class="dply-input mt-1 block w-full font-mono text-sm" placeholder="S3">
                                                <x-input-error :messages="$errors->get('buckets.'.$i.'.env_prefix')" class="mt-2" />
                                            </div>
                                            <div>
                                                <x-input-label :value="__('Backend')" />
                                                <select wire:model.live="buckets.{{ $i }}.backend" class="dply-input mt-1 block w-full text-sm">
                                                    <option value="digitalocean_spaces">DigitalOcean Spaces</option>
                                                    <option value="aws_s3">AWS S3</option>
                                                    <option value="cloudflare_r2">Cloudflare R2</option>
                                                </select>
                                            </div>
                                            <div>
                                                <x-input-label :value="__('Region')" />
                                                <input type="text" wire:model.blur="buckets.{{ $i }}.region" class="dply-input mt-1 block w-full font-mono text-sm" placeholder="{{ $region ?: 'nyc3' }}">
                                                <p class="mt-1 text-xs text-brand-mist">{{ __('Defaults to the app region.') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-4 py-3 text-xs text-brand-moss dark:border-brand-mist/20 dark:bg-zinc-800/40">
                                {{ __('No buckets attached. Add one below.') }}
                            </p>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="addBucket" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream">
                                <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Add bucket') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Custom domains --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-ink ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-cream">
                        <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-4">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Custom domains') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Hostnames you want pointed at this app. Each is attached automatically once provisioning finishes; DNS validation records appear on the app dashboard afterward.') }}</p>
                        </div>
                        @if (! empty($domains))
                            <ul class="divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/20">
                                @foreach ($domains as $i => $hostname)
                                    <li class="flex items-center justify-between gap-3 px-3 py-2">
                                        <span class="font-mono text-xs text-brand-ink dark:text-brand-cream">{{ $hostname }}</span>
                                        <button type="button" wire:click="removeDomain({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <div class="flex flex-wrap gap-2">
                            <input type="text" wire:model="new_domain" wire:keydown.enter.prevent="addDomain" placeholder="app.acme.com" class="dply-input min-w-[12rem] flex-1 font-mono text-sm">
                            <button type="button" wire:click="addDomain" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream dark:hover:bg-zinc-700">
                                <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                {{ __('Add domain') }}
                            </button>
                        </div>
                    </div>
                </div>
            </section>

            {{-- Background workers --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-ink ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-cream">
                        <x-heroicon-o-queue-list class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-4">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Background workers') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Queue workers and a Laravel scheduler. Each runs as a long-lived process inside the same image as your web service — the command you set has to be runnable in that image.') }}</p>
                        </div>
                        @unless ($backendSupportsWorkers)
                            <p class="rounded-lg bg-brand-gold/10 px-3 py-2 text-xs text-brand-ink">
                                {{ __('Background workers aren\'t supported on your current cloud account. Connect DigitalOcean from the credentials page to enable them.') }}
                            </p>
                        @else
                            @if ($mode === 'image' && ! empty($workers))
                                <p class="rounded-lg bg-brand-cream/60 px-3 py-2 text-xs text-brand-moss ring-1 ring-brand-ink/10 dark:bg-zinc-800/60 dark:text-brand-cream dark:ring-brand-mist/20">
                                    <span class="font-semibold text-brand-ink dark:text-brand-cream">{{ __('Heads up:') }}</span>
                                    {{ __('You\'re deploying a pre-built image. Each worker command runs inside that image — make sure the binary exists (e.g. don\'t set `php artisan` on an nginx image).') }}
                                </p>
                            @endif
                            @if (! empty($workers))
                                <div class="divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/20">
                                    @foreach ($workers as $i => $worker)
                                        <div class="grid grid-cols-1 gap-3 px-3 py-3 sm:grid-cols-12 sm:items-end">
                                            <div class="sm:col-span-2">
                                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Type') }}</label>
                                                <div class="mt-1 text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ $worker['type'] === 'scheduler' ? __('Scheduler') : __('Worker') }}</div>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</label>
                                                <input type="text" wire:model="workers.{{ $i }}.name" class="dply-input mt-1 block w-full font-mono text-xs">
                                            </div>
                                            <div class="sm:col-span-4">
                                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Command') }}</label>
                                                <input type="text" wire:model="workers.{{ $i }}.command" class="dply-input mt-1 block w-full font-mono text-xs" @disabled($worker['type'] === 'scheduler')>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</label>
                                                <select wire:model="workers.{{ $i }}.size" class="dply-input mt-1 block w-full text-xs">
                                                    <option value="small">small</option>
                                                    <option value="medium">medium</option>
                                                    <option value="large">large</option>
                                                    <option value="xlarge">xlarge</option>
                                                </select>
                                            </div>
                                            <div class="sm:col-span-1">
                                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Inst.') }}</label>
                                                <input type="number" min="1" max="50" wire:model="workers.{{ $i }}.instance_count" class="dply-input mt-1 block w-full text-xs" @disabled($worker['type'] === 'scheduler')>
                                            </div>
                                            <div class="flex sm:col-span-1 sm:justify-end">
                                                <button type="button" wire:click="removeWorker({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="addWorker('worker')" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream dark:hover:bg-zinc-700">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Queue worker') }}
                                </button>
                                <button type="button" wire:click="addWorker('scheduler')" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 disabled:opacity-50 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream dark:hover:bg-zinc-700" @disabled($this->hasScheduler())>
                                    <x-heroicon-o-clock class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Scheduler') }}
                                </button>
                            </div>
                        @endunless
                    </div>
                </div>
            </section>

            {{-- Deploy tasks --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-ink ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-cream">
                        <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-4">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Deploy tasks') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('One-shot commands tied to the deploy lifecycle. Migrations before traffic flips, cache warmers after, manual ops on demand. Each runs inside the same image as the web service.') }}</p>
                        </div>
                        @unless ($backendSupportsDeployTasks)
                            <p class="rounded-lg bg-brand-gold/10 px-3 py-2 text-xs text-brand-ink">
                                {{ __('Deploy tasks aren\'t supported on your current cloud account. Connect DigitalOcean from the credentials page to enable them.') }}
                            </p>
                        @else
                            <div class="space-y-3 rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4 dark:border-brand-mist/20 dark:bg-zinc-800/40">
                                <label class="flex cursor-pointer items-start gap-3">
                                    <input type="checkbox" wire:model.live="migrations_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                    <div class="space-y-0.5">
                                        <p class="text-sm font-semibold text-brand-ink">{{ __('Run migrations on deploy') }}</p>
                                        <p class="text-xs text-brand-moss">{{ __('Runs your migration command on PRE_DEPLOY — before the new instances accept traffic. If it fails, the rollout stops and the previous version keeps serving.') }}</p>
                                    </div>
                                </label>
                                @if ($migrations_enabled)
                                    <div>
                                        <x-input-label for="migrations_command" :value="__('Migrations command')" />
                                        <x-text-input id="migrations_command" wire:model="migrations_command" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="php artisan migrate --force" required />
                                        @if ($mode === 'image')
                                            <p class="mt-1 text-xs text-brand-mist">{{ __('Image mode — make sure this command exists inside your image.') }}</p>
                                        @endif
                                        <x-input-error :messages="$errors->get('migrations_command')" class="mt-2" />
                                    </div>
                                @endif
                            </div>

                            @if (! empty($deploy_tasks))
                                <div class="divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 dark:divide-brand-mist/15 dark:border-brand-mist/20">
                                    @foreach ($deploy_tasks as $i => $task)
                                        <div class="grid grid-cols-1 gap-3 px-3 py-3 sm:grid-cols-12 sm:items-end">
                                            <div class="sm:col-span-3">
                                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Trigger') }}</label>
                                                <select wire:model="deploy_tasks.{{ $i }}.trigger" class="dply-input mt-1 block w-full text-xs">
                                                    <option value="pre_deploy">{{ __('Pre-deploy') }}</option>
                                                    <option value="post_deploy">{{ __('Post-deploy') }}</option>
                                                    <option value="failed_deploy">{{ __('On failure') }}</option>
                                                    <option value="manual">{{ __('Manual (run now)') }}</option>
                                                </select>
                                            </div>
                                            <div class="sm:col-span-2">
                                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</label>
                                                <input type="text" wire:model="deploy_tasks.{{ $i }}.name" class="dply-input mt-1 block w-full font-mono text-xs">
                                                <x-input-error :messages="$errors->get('deploy_tasks.'.$i.'.name')" class="mt-2" />
                                            </div>
                                            <div class="sm:col-span-5">
                                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Command') }}</label>
                                                <input type="text" wire:model="deploy_tasks.{{ $i }}.command" class="dply-input mt-1 block w-full font-mono text-xs" placeholder="php artisan cache:warm">
                                                <x-input-error :messages="$errors->get('deploy_tasks.'.$i.'.command')" class="mt-2" />
                                            </div>
                                            <div class="sm:col-span-1">
                                                <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</label>
                                                <select wire:model="deploy_tasks.{{ $i }}.size" class="dply-input mt-1 block w-full text-xs">
                                                    <option value="small">small</option>
                                                    <option value="medium">medium</option>
                                                    <option value="large">large</option>
                                                    <option value="xlarge">xlarge</option>
                                                </select>
                                            </div>
                                            <div class="flex sm:col-span-1 sm:justify-end">
                                                <button type="button" wire:click="removeDeployTask({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            @if ($mode === 'image' && ! empty($deploy_tasks))
                                <p class="rounded-lg bg-brand-cream/60 px-3 py-2 text-xs text-brand-moss ring-1 ring-brand-ink/10 dark:bg-zinc-800/60 dark:text-brand-cream dark:ring-brand-mist/20">
                                    <span class="font-semibold text-brand-ink dark:text-brand-cream">{{ __('Heads up:') }}</span>
                                    {{ __('Each task runs inside your image — make sure the binary exists.') }}
                                </p>
                            @endif

                            <div class="flex flex-wrap gap-2">
                                <button type="button" wire:click="addDeployTask('pre_deploy')" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream dark:hover:bg-zinc-700">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Pre-deploy task') }}
                                </button>
                                <button type="button" wire:click="addDeployTask('post_deploy')" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream dark:hover:bg-zinc-700">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Post-deploy task') }}
                                </button>
                                <button type="button" wire:click="addDeployTask('manual')" class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-cream dark:hover:bg-zinc-700">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Manual task') }}
                                </button>
                            </div>
                        @endunless
                    </div>
                </div>
            </section>

            {{-- Autoscaling --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-ink ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-cream">
                        <x-heroicon-o-arrows-up-down class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-4">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" wire:model.live="autoscaling_enabled" class="mt-1 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                            <div class="space-y-0.5">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('CPU-target autoscaling') }}</p>
                                <p class="text-xs text-brand-moss">{{ __('When on, instance count overrides the fixed value above and floats between min and max based on CPU load.') }}</p>
                            </div>
                        </label>
                        @if ($autoscaling_enabled)
                            @if (! str_ends_with($size_tier, '-pro'))
                                <div class="rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-xs text-brand-ink">
                                    <p class="font-semibold">{{ __('Pro tier required') }}</p>
                                    <p class="mt-0.5 text-brand-moss">{{ __('CPU autoscaling only runs on Pro-tier instances. Switch the size above to a Pro option, or turn autoscaling off.') }}</p>
                                </div>
                            @endif
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <x-input-label for="autoscaling_min" :value="__('Min instances')" />
                                    <input id="autoscaling_min" type="number" min="1" max="50" wire:model="autoscaling_min" class="dply-input mt-1 block w-full font-mono text-sm">
                                    <x-input-error :messages="$errors->get('autoscaling_min')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="autoscaling_max" :value="__('Max instances')" />
                                    <input id="autoscaling_max" type="number" min="1" max="50" wire:model="autoscaling_max" class="dply-input mt-1 block w-full font-mono text-sm">
                                    <x-input-error :messages="$errors->get('autoscaling_max')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="autoscaling_cpu_percent" :value="__('CPU target %')" />
                                    <input id="autoscaling_cpu_percent" type="number" min="1" max="100" wire:model="autoscaling_cpu_percent" class="dply-input mt-1 block w-full font-mono text-sm">
                                    <x-input-error :messages="$errors->get('autoscaling_cpu_percent')" class="mt-2" />
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Health check --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-ink ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-cream">
                        <x-heroicon-o-heart class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-4">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" wire:model.live="health_check_enabled" class="mt-1 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                            <div class="space-y-0.5">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('HTTP health check') }}</p>
                                <p class="text-xs text-brand-moss">{{ __('dply probes this path on each instance; failing instances are restarted automatically.') }}</p>
                            </div>
                        </label>
                        @if ($health_check_enabled)
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                                <div class="sm:col-span-2">
                                    <x-input-label for="health_check_path" :value="__('Path')" />
                                    <input id="health_check_path" type="text" wire:model="health_check_path" class="dply-input mt-1 block w-full font-mono text-sm" placeholder="/healthz">
                                    <x-input-error :messages="$errors->get('health_check_path')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="health_check_period_seconds" :value="__('Period (s)')" />
                                    <input id="health_check_period_seconds" type="number" min="1" wire:model="health_check_period_seconds" class="dply-input mt-1 block w-full font-mono text-sm">
                                </div>
                                <div>
                                    <x-input-label for="health_check_timeout_seconds" :value="__('Timeout (s)')" />
                                    <input id="health_check_timeout_seconds" type="number" min="1" wire:model="health_check_timeout_seconds" class="dply-input mt-1 block w-full font-mono text-sm">
                                </div>
                                <div>
                                    <x-input-label for="health_check_failure_threshold" :value="__('Failure threshold')" />
                                    <input id="health_check_failure_threshold" type="number" min="1" wire:model="health_check_failure_threshold" class="dply-input mt-1 block w-full font-mono text-sm">
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </section>

            {{-- Alerts --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/40 text-brand-ink ring-1 ring-brand-ink/10 dark:bg-zinc-800 dark:text-brand-cream">
                        <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-4">
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Alerts') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('All four rules default on. Org-level destinations apply unless this site overrides them.') }}</p>
                        </div>

                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" wire:model.live="alert_deployment_failed_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                            <div>
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Deploy failed') }}</p>
                                <p class="text-xs text-brand-moss">{{ __('Fires whenever a deployment fails. No threshold.') }}</p>
                            </div>
                        </label>

                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" wire:model.live="alert_restart_count_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Restart loop') }}</p>
                                <p class="text-xs text-brand-moss">{{ __('Triggers when a component restarts more than N times in 5 minutes.') }}</p>
                                @if ($alert_restart_count_enabled)
                                    <div class="mt-2 inline-flex items-center gap-2 text-xs text-brand-moss">
                                        <span>{{ __('Threshold:') }}</span>
                                        <input type="number" min="1" max="100" wire:model="alert_restart_count_value" class="dply-input w-20 font-mono text-xs">
                                        <span>{{ __('restarts in 5m') }}</span>
                                    </div>
                                @endif
                            </div>
                        </label>

                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" wire:model.live="alert_cpu_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('CPU sustained') }}</p>
                                <p class="text-xs text-brand-moss">{{ __('Fires when CPU stays above the threshold for 5 minutes.') }}</p>
                                @if ($alert_cpu_enabled)
                                    <div class="mt-2 inline-flex items-center gap-2 text-xs text-brand-moss">
                                        <span>{{ __('Above') }}</span>
                                        <input type="number" min="1" max="100" wire:model="alert_cpu_value" class="dply-input w-20 font-mono text-xs">
                                        <span>% {{ __('for 5m') }}</span>
                                    </div>
                                @endif
                            </div>
                        </label>

                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" wire:model.live="alert_mem_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                            <div class="flex-1">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Memory sustained') }}</p>
                                <p class="text-xs text-brand-moss">{{ __('Fires when memory stays above the threshold for 5 minutes.') }}</p>
                                @if ($alert_mem_enabled)
                                    <div class="mt-2 inline-flex items-center gap-2 text-xs text-brand-moss">
                                        <span>{{ __('Above') }}</span>
                                        <input type="number" min="1" max="100" wire:model="alert_mem_value" class="dply-input w-20 font-mono text-xs">
                                        <span>% {{ __('for 5m') }}</span>
                                    </div>
                                @endif
                            </div>
                        </label>

                        <div class="border-t border-brand-ink/10 pt-4 dark:border-brand-mist/15">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" wire:model.live="alert_destinations_override_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage/40">
                                <div>
                                    <p class="text-sm font-semibold text-brand-ink">{{ __('Use custom destinations for this site') }}</p>
                                    <p class="text-xs text-brand-moss">{{ __('Override the org-level Slack/emails — useful when one app pages a different team.') }}</p>
                                </div>
                            </label>
                            @if ($alert_destinations_override_enabled)
                                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="alert_destinations_override_slack" :value="__('Slack webhook URL')" />
                                        <x-text-input id="alert_destinations_override_slack" wire:model="alert_destinations_override_slack" type="url" class="mt-1 block w-full font-mono text-xs" placeholder="https://hooks.slack.com/services/…" />
                                        <x-input-error :messages="$errors->get('alert_destinations_override_slack')" class="mt-2" />
                                    </div>
                                    <div>
                                        <x-input-label for="alert_destinations_override_emails" :value="__('Extra emails')" />
                                        <textarea id="alert_destinations_override_emails" wire:model="alert_destinations_override_emails" rows="2" class="dply-input mt-1 block w-full font-mono text-xs" placeholder="oncall@example.com"></textarea>
                                        <x-input-error :messages="$errors->get('alert_destinations_override_emails')" class="mt-2" />
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            {{-- Submit bar --}}
            <div class="flex flex-col-reverse gap-3 border-t border-brand-ink/8 pt-6 sm:flex-row sm:items-center sm:justify-between dark:border-brand-mist/15">
                <a href="{{ route('cloud.index') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 text-sm font-medium text-brand-moss transition-colors hover:text-brand-ink">
                    <x-heroicon-m-arrow-left class="h-4 w-4" aria-hidden="true" />
                    {{ __('Back to Cloud apps') }}
                </a>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="deploy"
                        class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-ink/90 disabled:cursor-not-allowed disabled:opacity-60 dark:shadow-none"
                    >
                        <span wire:loading.remove wire:target="deploy" class="inline-flex items-center gap-2 whitespace-nowrap">
                            <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Deploy app') }}
                        </span>
                        <span wire:loading wire:target="deploy" class="inline-flex items-center gap-2 whitespace-nowrap">
                            <x-spinner variant="cream" />
                            {{ __('Deploying…') }}
                        </span>
                    </button>
                </div>
            </div>
        </div>

        @include('livewire.cloud.partials.create-sidebar', ['cloudFee' => $cloudFee, 'costPreview' => $costPreview])
        </div>

        <div x-show="view === 'canvas'" x-cloak>
            @include('livewire.cloud.partials.create-canvas')
        </div>
    </form>
</div>
