<div class="mx-auto max-w-3xl px-6 py-10">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Cloud apps'), 'href' => route('cloud.index'), 'icon' => 'cloud'],
        ['label' => __('Deploy')],
    ]" />

    <header class="mb-8 space-y-1.5">
        <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
            <x-heroicon-o-cloud class="h-3 w-3" aria-hidden="true" />
            {{ __('Cloud') }}
        </span>
        <h1 class="text-3xl font-semibold tracking-tight text-brand-ink">{{ __('Deploy an app') }}</h1>
        <p class="text-sm text-brand-moss">{{ __('Point dply at a repository or image and we run it for you — global HTTPS, auto-scaling, and zero-config TLS.') }}</p>
    </header>

    @if ($connectedBackends->isEmpty() && ! $fakeCloudActive)
        <div class="dply-card mb-6 flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <div class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-gold/20 text-brand-rust">
                    <x-heroicon-o-link class="h-4 w-4" aria-hidden="true" />
                </div>
                <div class="space-y-1">
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Connect a cloud account to deploy') }}</p>
                    <p class="text-sm text-brand-moss">{{ __('dply needs a DigitalOcean or AWS account to run your app on. Connect once and we handle the rest.') }}</p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2 text-xs">
                <a href="{{ route('credentials.index', ['provider' => 'digitalocean']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 font-semibold text-brand-cream hover:bg-brand-ink/90">
                    {{ __('Connect DigitalOcean') }}
                </a>
                <a href="{{ route('credentials.index', ['provider' => 'aws_app_runner']) }}" wire:navigate class="font-medium text-brand-moss hover:text-brand-ink">{{ __('Use AWS instead') }}</a>
            </div>
        </div>
    @elseif ($connectedBackends->isEmpty() && $fakeCloudActive)
        <div data-testid="fake-cloud-active-notice" class="dply-card mb-6 flex items-start gap-3 p-5">
            <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-700">
                <x-heroicon-o-beaker class="h-4 w-4" aria-hidden="true" />
            </div>
            <div class="space-y-1">
                <p class="text-sm font-semibold text-brand-ink">{{ __('Sandbox mode is on — no real cloud account needed') }}</p>
                <p class="text-sm text-brand-moss">{{ __('Deployments land on a local sandbox so you can explore the flow. Live URLs are synthetic until you connect a real cloud account.') }}</p>
            </div>
        </div>
    @endif

    <form wire:submit="deploy" class="space-y-5">
        {{-- Source --}}
        <section class="dply-card overflow-hidden">
            <header class="flex items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                    <x-heroicon-o-rocket-launch class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                    {{ __('Source') }}
                </div>
            </header>
            <div class="space-y-5 p-6">
                <div role="tablist" aria-label="{{ __('Deployment source') }}" class="inline-flex w-full max-w-md rounded-xl border border-brand-ink/10 bg-brand-cream/60 p-1 text-sm">
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
                            <div role="radiogroup" aria-label="{{ __('Where to find the repo') }}" class="inline-flex rounded-lg border border-brand-ink/10 bg-brand-cream/60 p-1 text-xs">
                                <button type="button" role="radio" aria-checked="{{ $repo_source === 'connected' ? 'true' : 'false' }}" wire:click="$set('repo_source', 'connected')"
                                    @class([
                                        'rounded-md px-2.5 py-1 font-medium transition',
                                        'bg-brand-ink text-brand-cream shadow-sm' => $repo_source === 'connected',
                                        'text-brand-moss hover:text-brand-ink' => $repo_source !== 'connected',
                                    ])>{{ __('Pick from connected account') }}</button>
                                <button type="button" role="radio" aria-checked="{{ $repo_source === 'manual' ? 'true' : 'false' }}" wire:click="$set('repo_source', 'manual')"
                                    @class([
                                        'rounded-md px-2.5 py-1 font-medium transition',
                                        'bg-brand-ink text-brand-cream shadow-sm' => $repo_source === 'manual',
                                        'text-brand-moss hover:text-brand-ink' => $repo_source !== 'manual',
                                    ])>{{ __('Enter manually') }}</button>
                            </div>
                        @endif
                        <x-connect-provider-link>{{ __('Connect a provider') }} &rarr;</x-connect-provider-link>
                    </div>

                    @if ($repo_source === 'connected' && $linkedSourceControlAccounts !== [])
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="source_control_account_id" :value="__('Account')" />
                                <select id="source_control_account_id" wire:model.live="source_control_account_id" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm">
                                    @foreach ($linkedSourceControlAccounts as $account)
                                        <option value="{{ $account['id'] }}">{{ $account['label'] ?? $account['name'] ?? $account['id'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="repository_selection" :value="__('Repository')" />
                                <select id="repository_selection" wire:model.live="repository_selection" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm" required>
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
                            <x-text-input id="repo" wire:model="repo" type="text" class="mt-1 block w-full font-mono" required placeholder="acme/api" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('owner/name or full GitHub URL. dply pulls and builds it for you.') }}</p>
                            <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                        </div>
                    @endif

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="branch" :value="__('Branch')" />
                            <x-text-input id="branch" wire:model="branch" type="text" class="mt-1 block w-full font-mono" required placeholder="main" />
                            <x-input-error :messages="$errors->get('branch')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="dockerfile_path" :value="__('Dockerfile path (optional)')" />
                            <x-text-input id="dockerfile_path" wire:model="dockerfile_path" type="text" class="mt-1 block w-full font-mono" placeholder="Dockerfile" />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Leave blank for buildpack auto-detection.') }}</p>
                        </div>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-brand-ink">
                        <input type="checkbox" wire:model="deploy_on_push" class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage">
                        {{ __('Auto-deploy on push to this branch') }}
                    </label>

                    <div class="space-y-3 rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-brand-moss">{{ __('Preview what dply detects in this repo before you deploy. We build with a buildpack when no Dockerfile path is given.') }}</p>
                            <button type="button" wire:click="detectFromRepository" wire:loading.attr="disabled" wire:target="detectFromRepository" class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-ink/90 disabled:opacity-50">
                                <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                                <span wire:loading.remove wire:target="detectFromRepository">{{ __('Detect runtime') }}</span>
                                <span wire:loading wire:target="detectFromRepository">{{ __('Detecting…') }}</span>
                            </button>
                        </div>
                        @include('livewire.partials._runtime-detection-panel')
                    </div>
                @else
                    <div>
                        <x-input-label for="image" :value="__('Image')" />
                        <x-text-input id="image" wire:model="image" type="text" class="mt-1 block w-full font-mono" required placeholder="ghcr.io/acme/api:v1.2.3" />
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Public registry images work out of the box. For private images, connect a registry credential first.') }}</p>
                        <x-input-error :messages="$errors->get('image')" class="mt-2" />
                    </div>
                @endif
            </div>
        </section>

        {{-- Runtime --}}
        <section class="dply-card overflow-hidden">
            <header class="flex items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                    <x-heroicon-o-cpu-chip class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                    {{ __('Runtime') }}
                </div>
            </header>
            <div class="space-y-5 p-6">
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
                        <select id="size_tier" wire:model.live="size_tier" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm" required>
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
                    <select id="region" wire:model="region" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm" required>
                        @foreach ($regions as $r)
                            <option value="{{ $r['slug'] }}">{{ $r['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('region')" class="mt-2" />
                </div>
            </div>
        </section>

        {{-- Environment --}}
        <section class="dply-card overflow-hidden">
            <header class="flex items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                    <x-heroicon-o-variable class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                    {{ __('Environment') }}
                </div>
                <span class="text-[10px] uppercase tracking-wide text-brand-mist">{{ __('Optional') }}</span>
            </header>
            <div class="space-y-2 p-6">
                <textarea id="env_file_content" wire:model="env_file_content" rows="6" class="block w-full rounded-md border-brand-ink/15 bg-brand-cream/30 font-mono text-sm shadow-sm" placeholder="APP_ENV=production&#10;LOG_LEVEL=info"></textarea>
                <p class="text-xs text-brand-mist">{{ __('One KEY=value per line. Lines starting with # are ignored.') }}</p>
            </div>
        </section>

        {{-- Background workers --}}
        <section class="dply-card overflow-hidden">
            <header class="flex items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                <div class="space-y-0.5">
                    <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                        <x-heroicon-o-queue-list class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                        {{ __('Background workers') }}
                    </div>
                    <p class="text-xs text-brand-moss">
                        {{ __('Queue workers and a Laravel scheduler. Each runs as a long-lived process inside the same image as your web service — the command you set has to be runnable in that image.') }}
                    </p>
                </div>
            </header>
            <div class="p-6">
                @unless ($backendSupportsWorkers)
                    <p class="rounded-md bg-brand-gold/10 px-3 py-2 text-xs text-brand-ink">
                        {{ __('Background workers aren\'t supported on your current cloud account. Connect DigitalOcean from the credentials page to enable them.') }}
                    </p>
                @else
                    @if ($mode === 'image' && ! empty($workers))
                        <p class="mb-4 rounded-md bg-brand-cream/60 px-3 py-2 text-xs text-brand-moss ring-1 ring-brand-ink/10">
                            <span class="font-semibold text-brand-ink">{{ __('Heads up:') }}</span>
                            {{ __('You\'re deploying a pre-built image. Each worker command runs inside that image — make sure the binary exists (e.g. don\'t set `php artisan` on an nginx image).') }}
                        </p>
                    @endif
                    @if (! empty($workers))
                        <div class="divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10">
                            @foreach ($workers as $i => $worker)
                                <div class="grid grid-cols-1 gap-3 px-3 py-3 sm:grid-cols-12 sm:items-end">
                                    <div class="sm:col-span-2">
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Type') }}</label>
                                        <div class="mt-1 text-xs font-semibold text-brand-ink">{{ $worker['type'] === 'scheduler' ? __('Scheduler') : __('Worker') }}</div>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</label>
                                        <input type="text" wire:model="workers.{{ $i }}.name" class="mt-1 block w-full rounded-md border-brand-ink/15 text-xs font-mono shadow-sm">
                                    </div>
                                    <div class="sm:col-span-4">
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Command') }}</label>
                                        <input type="text" wire:model="workers.{{ $i }}.command" class="mt-1 block w-full rounded-md border-brand-ink/15 text-xs font-mono shadow-sm" @disabled($worker['type'] === 'scheduler')>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</label>
                                        <select wire:model="workers.{{ $i }}.size" class="mt-1 block w-full rounded-md border-brand-ink/15 text-xs shadow-sm">
                                            <option value="small">small</option>
                                            <option value="medium">medium</option>
                                            <option value="large">large</option>
                                            <option value="xlarge">xlarge</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-1">
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Inst.') }}</label>
                                        <input type="number" min="1" max="50" wire:model="workers.{{ $i }}.instance_count" class="mt-1 block w-full rounded-md border-brand-ink/15 text-xs shadow-sm" @disabled($worker['type'] === 'scheduler')>
                                    </div>
                                    <div class="sm:col-span-1 flex sm:justify-end">
                                        <button type="button" wire:click="removeWorker({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" wire:click="addWorker('worker')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40">
                            <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Queue worker') }}
                        </button>
                        <button type="button" wire:click="addWorker('scheduler')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40 disabled:opacity-50" @disabled($this->hasScheduler())>
                            <x-heroicon-o-clock class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Scheduler') }}
                        </button>
                    </div>
                @endunless
            </div>
        </section>

        {{-- Deploy tasks --}}
        <section class="dply-card overflow-hidden">
            <header class="flex items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                <div class="space-y-0.5">
                    <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                        <x-heroicon-o-bolt class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                        {{ __('Deploy tasks') }}
                    </div>
                    <p class="text-xs text-brand-moss">
                        {{ __('One-shot commands tied to the deploy lifecycle. Migrations before traffic flips, cache warmers after, manual ops on demand. Each runs inside the same image as the web service.') }}
                    </p>
                </div>
            </header>
            <div class="p-6 space-y-5">
                @unless ($backendSupportsDeployTasks)
                    <p class="rounded-md bg-brand-gold/10 px-3 py-2 text-xs text-brand-ink">
                        {{ __('Deploy tasks aren\'t supported on your current cloud account. Connect DigitalOcean from the credentials page to enable them.') }}
                    </p>
                @else
                    {{-- First-class migrations toggle --}}
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4 space-y-3">
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input type="checkbox" wire:model.live="migrations_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage">
                            <div class="space-y-0.5">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Run migrations on deploy') }}</p>
                                <p class="text-xs text-brand-moss">{{ __('Runs your migration command on PRE_DEPLOY — before the new instances accept traffic. If it fails, the rollout stops and the previous version keeps serving.') }}</p>
                            </div>
                        </label>
                        @if ($migrations_enabled)
                            <div>
                                <x-input-label for="migrations_command" :value="__('Migrations command')" />
                                <x-text-input id="migrations_command" wire:model="migrations_command" type="text" class="mt-1 block w-full font-mono" placeholder="php artisan migrate --force" required />
                                @if ($mode === 'image')
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('Image mode — make sure this command exists inside your image.') }}</p>
                                @endif
                                <x-input-error :messages="$errors->get('migrations_command')" class="mt-2" />
                            </div>
                        @endif
                    </div>

                    {{-- Extras repeater --}}
                    @if (! empty($deploy_tasks))
                        <div class="divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10">
                            @foreach ($deploy_tasks as $i => $task)
                                <div class="grid grid-cols-1 gap-3 px-3 py-3 sm:grid-cols-12 sm:items-end">
                                    <div class="sm:col-span-3">
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Trigger') }}</label>
                                        <select wire:model="deploy_tasks.{{ $i }}.trigger" class="mt-1 block w-full rounded-md border-brand-ink/15 text-xs shadow-sm">
                                            <option value="pre_deploy">{{ __('Pre-deploy') }}</option>
                                            <option value="post_deploy">{{ __('Post-deploy') }}</option>
                                            <option value="failed_deploy">{{ __('On failure') }}</option>
                                            <option value="manual">{{ __('Manual (run now)') }}</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Name') }}</label>
                                        <input type="text" wire:model="deploy_tasks.{{ $i }}.name" class="mt-1 block w-full rounded-md border-brand-ink/15 text-xs font-mono shadow-sm">
                                        <x-input-error :messages="$errors->get('deploy_tasks.'.$i.'.name')" class="mt-2" />
                                    </div>
                                    <div class="sm:col-span-5">
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Command') }}</label>
                                        <input type="text" wire:model="deploy_tasks.{{ $i }}.command" class="mt-1 block w-full rounded-md border-brand-ink/15 text-xs font-mono shadow-sm" placeholder="php artisan cache:warm">
                                        <x-input-error :messages="$errors->get('deploy_tasks.'.$i.'.command')" class="mt-2" />
                                    </div>
                                    <div class="sm:col-span-1">
                                        <label class="block text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Size') }}</label>
                                        <select wire:model="deploy_tasks.{{ $i }}.size" class="mt-1 block w-full rounded-md border-brand-ink/15 text-xs shadow-sm">
                                            <option value="small">small</option>
                                            <option value="medium">medium</option>
                                            <option value="large">large</option>
                                            <option value="xlarge">xlarge</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-1 flex sm:justify-end">
                                        <button type="button" wire:click="removeDeployTask({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($mode === 'image' && ! empty($deploy_tasks))
                        <p class="rounded-md bg-brand-cream/60 px-3 py-2 text-xs text-brand-moss ring-1 ring-brand-ink/10">
                            <span class="font-semibold text-brand-ink">{{ __('Heads up:') }}</span>
                            {{ __('Each task runs inside your image — make sure the binary exists.') }}
                        </p>
                    @endif

                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="addDeployTask('pre_deploy')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40">
                            <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Pre-deploy task') }}
                        </button>
                        <button type="button" wire:click="addDeployTask('post_deploy')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40">
                            <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Post-deploy task') }}
                        </button>
                        <button type="button" wire:click="addDeployTask('manual')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40">
                            <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Manual task') }}
                        </button>
                    </div>
                @endunless
            </div>
        </section>

        {{-- Database --}}
        <section class="dply-card overflow-hidden">
            <header class="flex items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                <div class="space-y-0.5">
                    <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                        <x-heroicon-o-circle-stack class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                        {{ __('Database') }}
                    </div>
                    <p class="text-xs text-brand-moss">{{ __('Attach a managed database so DB_* env vars land before the first deploy. Create new databases from the Cloud → Databases page.') }}</p>
                </div>
            </header>
            <div class="grid grid-cols-1 gap-3 p-6 sm:grid-cols-2">
                <div>
                    <x-input-label for="database_mode" :value="__('Mode')" />
                    <select id="database_mode" wire:model.live="database_mode" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm">
                        <option value="none">{{ __('No database') }}</option>
                        <option value="attach" @disabled($attachableDatabases->isEmpty())>{{ __('Attach existing') }}</option>
                        <option value="create">{{ __('Create new alongside') }}</option>
                    </select>
                    @if ($database_mode === 'create')
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Provisioning takes ~5-10 minutes. DB_* env vars are merged + the site is redeployed automatically once the cluster is online.') }}</p>
                    @endif
                </div>
                @if ($database_mode === 'attach')
                    <div>
                        <x-input-label for="database_id" :value="__('Database')" />
                        <select id="database_id" wire:model="database_id" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm" required>
                            <option value="">{{ __('— select —') }}</option>
                            @foreach ($attachableDatabases as $db)
                                <option value="{{ $db->id }}">{{ $db->name }} · {{ $db->engine }} @if ($db->status !== 'active')({{ $db->status }})@endif</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('database_id')" class="mt-2" />
                    </div>
                @endif
                @if ($database_mode === 'create')
                    <div>
                        <x-input-label for="new_database_name" :value="__('Cluster name')" />
                        <input id="new_database_name" type="text" wire:model="new_database_name" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm" placeholder="acme-prod" required>
                        <x-input-error :messages="$errors->get('new_database_name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="new_database_engine" :value="__('Engine')" />
                        <select id="new_database_engine" wire:model="new_database_engine" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm">
                            <option value="postgres">Postgres</option>
                            <option value="mysql">MySQL</option>
                            <option value="redis">Redis</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="new_database_size" :value="__('Size')" />
                        <select id="new_database_size" wire:model="new_database_size" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm">
                            <option value="small">small (1 vCPU / 1 GB)</option>
                            <option value="medium">medium (1 vCPU / 2 GB)</option>
                            <option value="large">large (2 vCPU / 4 GB)</option>
                        </select>
                    </div>
                @endif
            </div>
        </section>

        {{-- Custom domains --}}
        <section class="dply-card overflow-hidden">
            <header class="flex items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                <div class="space-y-0.5">
                    <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                        <x-heroicon-o-globe-alt class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                        {{ __('Custom domains') }}
                    </div>
                    <p class="text-xs text-brand-moss">{{ __('Hostnames you want pointed at this app. Each is attached automatically once the app finishes provisioning; DNS validation records appear on the app dashboard afterward.') }}</p>
                </div>
            </header>
            <div class="p-6">
                @if (! empty($domains))
                    <ul class="mb-4 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10">
                        @foreach ($domains as $i => $hostname)
                            <li class="flex items-center justify-between gap-3 px-3 py-2">
                                <span class="font-mono text-xs text-brand-ink">{{ $hostname }}</span>
                                <button type="button" wire:click="removeDomain({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <div class="flex flex-wrap gap-2">
                    <input type="text" wire:model="new_domain" wire:keydown.enter.prevent="addDomain" placeholder="app.acme.com" class="flex-1 min-w-[12rem] rounded-md border-brand-ink/15 font-mono text-xs shadow-sm">
                    <button type="button" wire:click="addDomain" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink transition hover:bg-brand-cream/40">
                        <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Add domain') }}
                    </button>
                </div>
            </div>
        </section>

        {{-- Autoscaling --}}
        <section class="dply-card overflow-hidden">
            <header class="flex items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model.live="autoscaling_enabled" class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage">
                    <div class="space-y-0.5">
                        <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                            <x-heroicon-o-arrows-up-down class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                            {{ __('CPU-target autoscaling') }}
                        </div>
                        <p class="text-xs text-brand-moss">{{ __('When on, instance count overrides the fixed value above and floats between min and max based on CPU load.') }}</p>
                    </div>
                </label>
            </header>
            @if ($autoscaling_enabled)
                @if (! str_ends_with($size_tier, '-pro'))
                    <div class="border-b border-brand-ink/10 bg-brand-gold/10 px-6 py-3 text-xs text-brand-ink">
                        <p class="font-semibold">{{ __('Pro tier required') }}</p>
                        <p class="mt-0.5 text-brand-moss">{{ __('CPU autoscaling only runs on Pro-tier instances. Switch the size above to a Pro option, or turn autoscaling off.') }}</p>
                    </div>
                @endif
                <div class="grid grid-cols-1 gap-3 p-6 sm:grid-cols-3">
                    <div>
                        <x-input-label for="autoscaling_min" :value="__('Min instances')" />
                        <input id="autoscaling_min" type="number" min="1" max="50" wire:model="autoscaling_min" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm font-mono">
                        <x-input-error :messages="$errors->get('autoscaling_min')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="autoscaling_max" :value="__('Max instances')" />
                        <input id="autoscaling_max" type="number" min="1" max="50" wire:model="autoscaling_max" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm font-mono">
                        <x-input-error :messages="$errors->get('autoscaling_max')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="autoscaling_cpu_percent" :value="__('CPU target %')" />
                        <input id="autoscaling_cpu_percent" type="number" min="1" max="100" wire:model="autoscaling_cpu_percent" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm font-mono">
                        <x-input-error :messages="$errors->get('autoscaling_cpu_percent')" class="mt-2" />
                    </div>
                </div>
            @endif
        </section>

        {{-- Health check --}}
        <section class="dply-card overflow-hidden">
            <header class="flex items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model.live="health_check_enabled" class="rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage">
                    <div class="space-y-0.5">
                        <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                            <x-heroicon-o-heart class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                            {{ __('HTTP health check') }}
                        </div>
                        <p class="text-xs text-brand-moss">{{ __('dply probes this path on each instance; failing instances are restarted automatically.') }}</p>
                    </div>
                </label>
            </header>
            @if ($health_check_enabled)
                <div class="grid grid-cols-1 gap-3 p-6 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <x-input-label for="health_check_path" :value="__('Path')" />
                        <input id="health_check_path" type="text" wire:model="health_check_path" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-sm shadow-sm" placeholder="/healthz">
                        <x-input-error :messages="$errors->get('health_check_path')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="health_check_period_seconds" :value="__('Period (s)')" />
                        <input id="health_check_period_seconds" type="number" min="1" wire:model="health_check_period_seconds" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm font-mono">
                    </div>
                    <div>
                        <x-input-label for="health_check_timeout_seconds" :value="__('Timeout (s)')" />
                        <input id="health_check_timeout_seconds" type="number" min="1" wire:model="health_check_timeout_seconds" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm font-mono">
                    </div>
                    <div>
                        <x-input-label for="health_check_failure_threshold" :value="__('Failure threshold')" />
                        <input id="health_check_failure_threshold" type="number" min="1" wire:model="health_check_failure_threshold" class="mt-1 block w-full rounded-md border-brand-ink/15 text-sm shadow-sm font-mono">
                    </div>
                </div>
            @endif
        </section>

        {{-- Alerts --}}
        <section class="dply-card overflow-hidden">
            <header class="flex items-center justify-between gap-2 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-3.5">
                <div class="space-y-0.5">
                    <div class="flex items-center gap-2 text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                        <x-heroicon-o-bell-alert class="h-3 w-3 text-brand-moss" aria-hidden="true" />
                        {{ __('Alerts') }}
                    </div>
                    <p class="text-xs text-brand-moss">{{ __('All four rules default on. Org-level destinations apply unless this site overrides them.') }}</p>
                </div>
            </header>
            <div class="space-y-4 p-6">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="alert_deployment_failed_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage">
                    <div>
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Deploy failed') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Fires whenever a deployment fails. No threshold.') }}</p>
                    </div>
                </label>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="alert_restart_count_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage">
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Restart loop') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Triggers when a component restarts more than N times in 5 minutes.') }}</p>
                        @if ($alert_restart_count_enabled)
                            <div class="mt-2 inline-flex items-center gap-2 text-xs text-brand-moss">
                                <span>{{ __('Threshold:') }}</span>
                                <input type="number" min="1" max="100" wire:model="alert_restart_count_value" class="w-20 rounded-md border-brand-ink/15 text-xs font-mono shadow-sm">
                                <span>{{ __('restarts in 5m') }}</span>
                            </div>
                        @endif
                    </div>
                </label>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="alert_cpu_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage">
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('CPU sustained') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Fires when CPU stays above the threshold for 5 minutes.') }}</p>
                        @if ($alert_cpu_enabled)
                            <div class="mt-2 inline-flex items-center gap-2 text-xs text-brand-moss">
                                <span>{{ __('Above') }}</span>
                                <input type="number" min="1" max="100" wire:model="alert_cpu_value" class="w-20 rounded-md border-brand-ink/15 text-xs font-mono shadow-sm">
                                <span>% {{ __('for 5m') }}</span>
                            </div>
                        @endif
                    </div>
                </label>

                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="alert_mem_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage">
                    <div class="flex-1">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Memory sustained') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Fires when memory stays above the threshold for 5 minutes.') }}</p>
                        @if ($alert_mem_enabled)
                            <div class="mt-2 inline-flex items-center gap-2 text-xs text-brand-moss">
                                <span>{{ __('Above') }}</span>
                                <input type="number" min="1" max="100" wire:model="alert_mem_value" class="w-20 rounded-md border-brand-ink/15 text-xs font-mono shadow-sm">
                                <span>% {{ __('for 5m') }}</span>
                            </div>
                        @endif
                    </div>
                </label>

                <div class="border-t border-brand-ink/10 pt-4">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" wire:model.live="alert_destinations_override_enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage">
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
                                <textarea id="alert_destinations_override_emails" wire:model="alert_destinations_override_emails" rows="2" class="mt-1 block w-full rounded-md border-brand-ink/15 font-mono text-xs shadow-sm" placeholder="oncall@example.com"></textarea>
                                <x-input-error :messages="$errors->get('alert_destinations_override_emails')" class="mt-2" />
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        {{-- Cost + submit --}}
        @if (is_string($costPreview['error'] ?? null) && $costPreview['error'] !== '')
            <div class="dply-card flex items-start gap-3 border-rose-200 bg-rose-50/60 p-4">
                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-rose-700" aria-hidden="true" />
                <div class="space-y-0.5">
                    <p class="text-sm font-semibold text-rose-900">{{ __('Cloud provider rejected this spec') }}</p>
                    <p class="text-xs text-rose-800">{{ $costPreview['error'] }}</p>
                </div>
            </div>
        @endif

        <div class="dply-card flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-start gap-3">
                <div class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-sage/15 text-brand-forest">
                    <x-heroicon-o-currency-dollar class="h-4 w-4" aria-hidden="true" />
                </div>
                <div class="space-y-0.5">
                    <p class="text-sm font-semibold text-brand-ink">
                        {{ __('Estimated cost:') }}
                        @if (is_numeric($costPreview['value'] ?? null))
                            ${{ number_format($costPreview['value'] + $cloudFee, 2) }}/mo
                            <span class="ml-1 text-xs font-medium text-brand-mist">({{ __('cloud') }} ${{ number_format($costPreview['value'], 2) }} + {{ __('dply fee') }} ${{ number_format($cloudFee, 2) }})</span>
                        @else
                            ${{ number_format($cloudFee, 2) }}/mo
                            <span class="ml-1 text-xs font-medium text-brand-mist">({{ __('dply fee — click Estimate for cloud cost') }})</span>
                        @endif
                    </p>
                    <p class="text-xs text-brand-moss">{{ __('Flat dply per-app fee plus your cloud account\'s usage. Branch previews are free.') }}</p>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" wire:click="recomputeCostPreview" wire:loading.attr="disabled" wire:target="recomputeCostPreview" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-medium text-brand-ink transition hover:bg-brand-cream/40 disabled:opacity-50">
                    <x-heroicon-o-calculator class="h-3.5 w-3.5" aria-hidden="true" />
                    <span wire:loading.remove wire:target="recomputeCostPreview">{{ __('Estimate') }}</span>
                    <span wire:loading wire:target="recomputeCostPreview">{{ __('Calling cloud…') }}</span>
                </button>
                <a href="{{ route('cloud.index') }}" wire:navigate class="text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</a>
                <button type="submit" wire:loading.attr="disabled" wire:target="deploy" class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm transition hover:bg-brand-ink/90 disabled:opacity-60">
                    <span wire:loading.remove wire:target="deploy" class="inline-flex items-center gap-2">
                        <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" />
                        {{ __('Deploy') }}
                    </span>
                    <span wire:loading wire:target="deploy" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Deploying…') }}
                    </span>
                </button>
            </div>
        </div>
    </form>
</div>
