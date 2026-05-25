<div class="mx-auto max-w-3xl px-6 py-10">
    <header class="mb-8">
        <h1 class="text-3xl font-semibold text-slate-900">{{ __('Deploy a container app') }}</h1>
        <p class="mt-2 text-sm text-slate-600">{{ __('Push a Docker image to the dply cloud platform. We provision it on a managed container backend (DigitalOcean App Platform or AWS App Runner) under the hood — you get global HTTPS, auto-scaling, and zero-config TLS.') }}</p>
    </header>

    @if ($connectedBackends->isEmpty() && ! $fakeCloudActive)
        <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 text-sm text-amber-900">
            <p class="font-semibold">{{ __('No container backend connected') }}</p>
            <p class="mt-1">{{ __('Connect a DigitalOcean App Platform or AWS App Runner credential first — that\'s the cloud account dply uses to run your container under the hood.') }}</p>
            <p class="mt-3">
                <a href="{{ route('credentials.index', ['provider' => 'digitalocean_app_platform']) }}" wire:navigate class="font-medium text-amber-900 underline">{{ __('Connect DigitalOcean') }}</a>
                <span class="mx-2 text-amber-400">·</span>
                <a href="{{ route('credentials.index', ['provider' => 'aws_app_runner']) }}" wire:navigate class="font-medium text-amber-900 underline">{{ __('Connect AWS App Runner') }}</a>
            </p>
        </div>
    @elseif ($connectedBackends->isEmpty() && $fakeCloudActive)
        <div data-testid="fake-cloud-active-notice" class="rounded-2xl border border-sky-200 bg-sky-50/60 p-4 text-sm text-sky-900">
            <p class="font-semibold">{{ __('Fake-cloud mode is on — no real DO/AWS account needed') }}</p>
            <p class="mt-1">{{ __('Deployments will land on the in-memory fake backend. Live URLs are synthetic. Connect a real credential to switch over.') }}</p>
        </div>
    @endif

    <form wire:submit="deploy" class="mt-8 space-y-6">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <div role="tablist" aria-label="{{ __('Deployment source') }}" class="mb-5 inline-flex rounded-xl border border-slate-200 bg-slate-50 p-1 text-sm">
                <button type="button" role="tab" aria-selected="{{ $mode === 'image' ? 'true' : 'false' }}" wire:click="$set('mode', 'image')"
                    @class([
                        'rounded-lg px-3 py-1.5 font-medium transition',
                        'bg-white text-slate-900 shadow-sm' => $mode === 'image',
                        'text-slate-600 hover:text-slate-900' => $mode !== 'image',
                    ])>{{ __('Container image') }}</button>
                <button type="button" role="tab" aria-selected="{{ $mode === 'source' ? 'true' : 'false' }}" wire:click="$set('mode', 'source')"
                    @class([
                        'rounded-lg px-3 py-1.5 font-medium transition',
                        'bg-white text-slate-900 shadow-sm' => $mode === 'source',
                        'text-slate-600 hover:text-slate-900' => $mode !== 'source',
                    ])>{{ __('Deploy from source') }}</button>
            </div>

            <div class="space-y-4">
                <div>
                    <x-input-label for="name" :value="__('App name')" />
                    <x-text-input id="name" wire:model="name" type="text" class="mt-1 block w-full" required placeholder="acme-api" />
                    <x-input-error :messages="$errors->get('name')" class="mt-2" />
                </div>
                @if ($mode === 'source')
                    @if ($backend === 'aws_app_runner' && ! $awsSourceReady)
                        <div data-testid="aws-github-connection-missing" class="rounded-xl border border-amber-300 bg-amber-50/70 p-4 text-sm text-amber-900">
                            <p class="font-semibold">{{ __('AWS App Runner needs a GitHub connection') }}</p>
                            <p class="mt-1">{{ __('App Runner can only build from a GitHub repo when an authorized connection ARN is attached to the credential. Set up the connection in the AWS console, then store the ARN as github_connection_arn on this credential.') }}</p>
                        </div>
                    @endif
                    <div class="flex flex-wrap items-center gap-3">
                        @if ($linkedSourceControlAccounts !== [])
                            <div role="radiogroup" aria-label="{{ __('Where to find the repo') }}" class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-1 text-xs">
                                <button type="button" role="radio" aria-checked="{{ $repo_source === 'connected' ? 'true' : 'false' }}" wire:click="$set('repo_source', 'connected')"
                                    @class([
                                        'rounded-md px-2.5 py-1 font-medium transition',
                                        'bg-white text-slate-900 shadow-sm' => $repo_source === 'connected',
                                        'text-slate-600 hover:text-slate-900' => $repo_source !== 'connected',
                                    ])>{{ __('Pick from connected account') }}</button>
                                <button type="button" role="radio" aria-checked="{{ $repo_source === 'manual' ? 'true' : 'false' }}" wire:click="$set('repo_source', 'manual')"
                                    @class([
                                        'rounded-md px-2.5 py-1 font-medium transition',
                                        'bg-white text-slate-900 shadow-sm' => $repo_source === 'manual',
                                        'text-slate-600 hover:text-slate-900' => $repo_source !== 'manual',
                                    ])>{{ __('Enter manually') }}</button>
                            </div>
                        @endif
                        <x-connect-provider-link>{{ __('Connect a provider') }} &rarr;</x-connect-provider-link>
                    </div>

                    @if ($repo_source === 'connected' && $linkedSourceControlAccounts !== [])
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="source_control_account_id" :value="__('Account')" />
                                <select id="source_control_account_id" wire:model.live="source_control_account_id" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                                    @foreach ($linkedSourceControlAccounts as $account)
                                        <option value="{{ $account['id'] }}">{{ $account['label'] ?? $account['name'] ?? $account['id'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <x-input-label for="repository_selection" :value="__('Repository')" />
                                <select id="repository_selection" wire:model.live="repository_selection" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" required>
                                    <option value="">{{ __('Select a repository…') }}</option>
                                    @foreach ($availableRepositories as $r)
                                        <option value="{{ $r['url'] }}">{{ $r['name'] ?? $r['url'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        @if ($repo !== '')
                            <p class="text-xs text-slate-500">{{ __('Will deploy :repo on branch :branch.', ['repo' => $repo, 'branch' => $branch]) }}</p>
                        @endif
                        <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                    @else
                        <div>
                            <x-input-label for="repo" :value="__('GitHub repo')" />
                            <x-text-input id="repo" wire:model="repo" type="text" class="mt-1 block w-full font-mono" required placeholder="acme/api" />
                            <p class="mt-1 text-xs text-slate-500">{{ __('owner/name or full GitHub URL. The backend pulls and builds it for you.') }}</p>
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
                            <p class="mt-1 text-xs text-slate-500">{{ __('Leave blank for buildpack auto-detection.') }}</p>
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="deploy_on_push" class="rounded border-slate-300">
                        {{ __('Auto-deploy on push to this branch') }}
                    </label>

                    <div class="space-y-3 rounded-xl border border-slate-200 bg-slate-50/70 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-sm text-slate-600">{{ __('Preview what dply detects in this repo before you deploy. The backend builds it with a buildpack when no Dockerfile path is given.') }}</p>
                            <button type="button" wire:click="detectFromRepository" wire:loading.attr="disabled" wire:target="detectFromRepository" class="inline-flex shrink-0 items-center justify-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-brand-ink/90 disabled:opacity-50">
                                <span wire:loading.remove wire:target="detectFromRepository">{{ __('Detect runtime') }}</span>
                                <span wire:loading wire:target="detectFromRepository">{{ __('Detecting…') }}</span>
                            </button>
                        </div>
                        @include('livewire.partials._runtime-detection-panel')
                    </div>
                @else
                    <div>
                        <x-input-label for="image" :value="__('Container image')" />
                        <x-text-input id="image" wire:model="image" type="text" class="mt-1 block w-full" required placeholder="ghcr.io/acme/api:v1.2.3" />
                        <p class="mt-1 text-xs text-slate-500">{{ __('Public registry images work out of the box. For private images, connect a registry credential first.') }}</p>
                        <x-input-error :messages="$errors->get('image')" class="mt-2" />
                    </div>
                @endif
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div>
                        <x-input-label for="port" :value="__('HTTP port')" />
                        <x-text-input id="port" wire:model="port" type="number" min="1" max="65535" class="mt-1 block w-full" required />
                        <x-input-error :messages="$errors->get('port')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="instances" :value="__('Instances')" />
                        <x-text-input id="instances" wire:model="instances" type="number" min="1" max="50" class="mt-1 block w-full" required />
                        <p class="mt-1 text-xs text-slate-500">{{ __('Fixed instance count. Use dply:cloud:scale to change later.') }}</p>
                        <x-input-error :messages="$errors->get('instances')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="size_tier" :value="__('Size')" />
                        <select id="size_tier" wire:model="size_tier" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" required>
                            <option value="small">{{ __('Small (DO basic-xxs / AWS 256/512)') }}</option>
                            <option value="medium">{{ __('Medium (DO basic-xs / AWS 512/1024)') }}</option>
                            <option value="large">{{ __('Large (DO basic-s / AWS 1024/2048)') }}</option>
                            <option value="xlarge">{{ __('XLarge (DO basic-m / AWS 2048/4096)') }}</option>
                        </select>
                        <x-input-error :messages="$errors->get('size_tier')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="backend" :value="__('Backend')" />
                        <select id="backend" wire:model.live="backend" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="auto">{{ __('Let dply choose') }}</option>
                            <option value="digitalocean_app_platform">{{ __('DigitalOcean App Platform') }}</option>
                            <option value="aws_app_runner">{{ __('AWS App Runner') }}</option>
                        </select>
                    </div>
                </div>
                <div>
                    <x-input-label for="region" :value="__('Region')" />
                    <select id="region" wire:model="region" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" required>
                        @foreach ($regions as $r)
                            <option value="{{ $r['slug'] }}">{{ $r['label'] }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('region')" class="mt-2" />
                </div>
                <div>
                    <x-input-label for="env_file_content" :value="__('Environment variables (optional)')" />
                    <textarea id="env_file_content" wire:model="env_file_content" rows="6" class="mt-1 block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm" placeholder="APP_ENV=production&#10;LOG_LEVEL=info"></textarea>
                    <p class="mt-1 text-xs text-slate-500">{{ __('One KEY=value per line. Lines starting with # are ignored.') }}</p>
                </div>
            </div>
        </div>

        {{-- Workers --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-baseline justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-700">{{ __('Background workers') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Queue workers + a Laravel scheduler. Each becomes a long-running App Platform component built from the same source as the web service.') }}</p>
                </div>
            </div>

            @unless ($backendSupportsWorkers)
                <p class="mt-4 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    {{ __('AWS App Runner does not support background workers. Switch the backend to DigitalOcean App Platform to add workers.') }}
                </p>
            @else
                @if (! empty($workers))
                    <div class="mt-4 divide-y divide-slate-200 rounded-lg border border-slate-200">
                        @foreach ($workers as $i => $worker)
                            <div class="grid grid-cols-1 gap-3 px-3 py-3 sm:grid-cols-12 sm:items-end">
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ __('Type') }}</label>
                                    <div class="mt-1 text-xs font-semibold text-slate-900">{{ $worker['type'] === 'scheduler' ? __('Scheduler') : __('Worker') }}</div>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ __('Name') }}</label>
                                    <input type="text" wire:model="workers.{{ $i }}.name" class="mt-1 block w-full rounded-md border-slate-300 text-xs font-mono shadow-sm">
                                </div>
                                <div class="sm:col-span-4">
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ __('Command') }}</label>
                                    <input type="text" wire:model="workers.{{ $i }}.command" class="mt-1 block w-full rounded-md border-slate-300 text-xs font-mono shadow-sm" @disabled($worker['type'] === 'scheduler')>
                                </div>
                                <div class="sm:col-span-2">
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ __('Size') }}</label>
                                    <select wire:model="workers.{{ $i }}.size" class="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm">
                                        <option value="small">small</option>
                                        <option value="medium">medium</option>
                                        <option value="large">large</option>
                                        <option value="xlarge">xlarge</option>
                                    </select>
                                </div>
                                <div class="sm:col-span-1">
                                    <label class="block text-[10px] font-semibold uppercase tracking-wide text-slate-500">{{ __('Inst.') }}</label>
                                    <input type="number" min="1" max="50" wire:model="workers.{{ $i }}.instance_count" class="mt-1 block w-full rounded-md border-slate-300 text-xs shadow-sm" @disabled($worker['type'] === 'scheduler')>
                                </div>
                                <div class="sm:col-span-1 flex sm:justify-end">
                                    <button type="button" wire:click="removeWorker({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="mt-4 flex flex-wrap gap-3">
                    <button type="button" wire:click="addWorker('worker')" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                        + {{ __('Queue worker') }}
                    </button>
                    <button type="button" wire:click="addWorker('scheduler')" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50" @disabled($this->hasScheduler())>
                        + {{ __('Scheduler') }}
                    </button>
                </div>
            @endunless
        </div>

        {{-- Database --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex items-baseline justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-700">{{ __('Database') }}</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ __('Attach a managed database so DB_* env vars land before the first deploy. Create new databases from the Cloud → Databases page.') }}</p>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="database_mode" :value="__('Mode')" />
                    <select id="database_mode" wire:model.live="database_mode" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                        <option value="none">{{ __('No database') }}</option>
                        <option value="attach" @disabled($attachableDatabases->isEmpty())>{{ __('Attach existing') }}</option>
                        <option value="create">{{ __('Create new alongside') }}</option>
                    </select>
                    @if ($database_mode === 'create')
                        <p class="mt-1 text-xs text-slate-500">{{ __('Provisioning takes ~5-10 minutes. DB_* env vars are merged + the site is redeployed automatically once the cluster is online.') }}</p>
                    @endif
                </div>
                @if ($database_mode === 'attach')
                    <div>
                        <x-input-label for="database_id" :value="__('Database')" />
                        <select id="database_id" wire:model="database_id" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" required>
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
                        <input id="new_database_name" type="text" wire:model="new_database_name" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm" placeholder="acme-prod" required>
                        <x-input-error :messages="$errors->get('new_database_name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="new_database_engine" :value="__('Engine')" />
                        <select id="new_database_engine" wire:model="new_database_engine" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="postgres">Postgres</option>
                            <option value="mysql">MySQL</option>
                            <option value="redis">Redis</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="new_database_size" :value="__('Size')" />
                        <select id="new_database_size" wire:model="new_database_size" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="small">small (1 vCPU / 1 GB)</option>
                            <option value="medium">medium (1 vCPU / 2 GB)</option>
                            <option value="large">large (2 vCPU / 4 GB)</option>
                        </select>
                    </div>
                @endif
            </div>
        </div>

        {{-- Custom domains --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-700">{{ __('Custom domains') }}</h2>
                <p class="mt-1 text-xs text-slate-500">{{ __('Hostnames you want pointed at this site. Each is attached automatically once the site finishes provisioning; the backend returns DNS validation records you can find on the site dashboard afterward.') }}</p>
            </div>

            @if (! empty($domains))
                <ul class="mt-4 divide-y divide-slate-200 rounded-lg border border-slate-200">
                    @foreach ($domains as $i => $hostname)
                        <li class="flex items-center justify-between gap-3 px-3 py-2">
                            <span class="font-mono text-xs text-slate-900">{{ $hostname }}</span>
                            <button type="button" wire:click="removeDomain({{ $i }})" class="text-[11px] font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                        </li>
                    @endforeach
                </ul>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                <input type="text" wire:model="new_domain" wire:keydown.enter.prevent="addDomain" placeholder="app.acme.com" class="flex-1 min-w-[12rem] rounded-md border-slate-300 font-mono text-xs shadow-sm">
                <button type="button" wire:click="addDomain" class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">+ {{ __('Add domain') }}</button>
            </div>
        </div>

        {{-- Autoscaling --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="autoscaling_enabled" class="rounded border-slate-300">
                <span class="text-sm font-semibold uppercase tracking-wide text-slate-700">{{ __('CPU-target autoscaling') }}</span>
            </label>
            <p class="mt-1 text-xs text-slate-500">{{ __('When on, instance count overrides the fixed value above and floats between min and max based on CPU load.') }}</p>
            @if ($autoscaling_enabled)
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <x-input-label for="autoscaling_min" :value="__('Min instances')" />
                        <input id="autoscaling_min" type="number" min="1" max="50" wire:model="autoscaling_min" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                        <x-input-error :messages="$errors->get('autoscaling_min')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="autoscaling_max" :value="__('Max instances')" />
                        <input id="autoscaling_max" type="number" min="1" max="50" wire:model="autoscaling_max" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                        <x-input-error :messages="$errors->get('autoscaling_max')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="autoscaling_cpu_percent" :value="__('CPU target %')" />
                        <input id="autoscaling_cpu_percent" type="number" min="1" max="100" wire:model="autoscaling_cpu_percent" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                        <x-input-error :messages="$errors->get('autoscaling_cpu_percent')" class="mt-2" />
                    </div>
                </div>
            @endif
        </div>

        {{-- Health check --}}
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <label class="flex items-center gap-2">
                <input type="checkbox" wire:model.live="health_check_enabled" class="rounded border-slate-300">
                <span class="text-sm font-semibold uppercase tracking-wide text-slate-700">{{ __('HTTP health check') }}</span>
            </label>
            <p class="mt-1 text-xs text-slate-500">{{ __('The backend probes this path on each instance; failing instances are restarted automatically.') }}</p>
            @if ($health_check_enabled)
                <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <x-input-label for="health_check_path" :value="__('Path')" />
                        <input id="health_check_path" type="text" wire:model="health_check_path" class="mt-1 block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm" placeholder="/healthz">
                        <x-input-error :messages="$errors->get('health_check_path')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="health_check_period_seconds" :value="__('Period (s)')" />
                        <input id="health_check_period_seconds" type="number" min="1" wire:model="health_check_period_seconds" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                    </div>
                    <div>
                        <x-input-label for="health_check_timeout_seconds" :value="__('Timeout (s)')" />
                        <input id="health_check_timeout_seconds" type="number" min="1" wire:model="health_check_timeout_seconds" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                    </div>
                    <div>
                        <x-input-label for="health_check_failure_threshold" :value="__('Failure threshold')" />
                        <input id="health_check_failure_threshold" type="number" min="1" wire:model="health_check_failure_threshold" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                    </div>
                </div>
            @endif
        </div>

        <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-600">
            <span class="font-semibold text-slate-900">{{ __('Estimated cost:') }} ${{ number_format($cloudFee, 2) }}/mo</span>
            — {{ __('a flat dply per-app fee once the container is live. Branch previews are free. Underlying container runtime (DigitalOcean App Platform or AWS App Runner) is billed separately by your cloud provider.') }}
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('sites.index') }}" wire:navigate class="text-sm font-medium text-slate-700 hover:text-slate-900">{{ __('Cancel') }}</a>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="deploy">
                <span wire:loading.remove wire:target="deploy">{{ __('Deploy to dply cloud') }}</span>
                <span wire:loading wire:target="deploy" class="inline-flex items-center justify-center gap-2">
                    <x-spinner variant="cream" />
                    {{ __('Deploying…') }}
                </span>
            </x-primary-button>
        </div>
    </form>

</div>
