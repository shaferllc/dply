<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
        ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
        ['label' => __('Create'), 'icon' => 'plus'],
    ]" />

    <header class="relative mt-6 overflow-hidden rounded-3xl border border-brand-ink/10 bg-gradient-to-br from-brand-cream via-white to-brand-sand/25 px-6 py-8 shadow-sm sm:px-10 sm:py-10 dark:border-brand-mist/20 dark:from-zinc-900 dark:via-zinc-900 dark:to-brand-sand/10">
        <div class="pointer-events-none absolute -end-16 -top-16 h-56 w-56 rounded-full bg-brand-sage/15 blur-3xl dark:bg-brand-sage/10" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-20 -start-12 h-48 w-48 rounded-full bg-brand-gold/15 blur-3xl dark:bg-brand-gold/10" aria-hidden="true"></div>
        <div class="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-2xl">
                <div class="inline-flex items-center gap-2 rounded-full border border-brand-sage/25 bg-brand-sage/10 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-forest dark:border-brand-sage/30 dark:bg-brand-sage/15 dark:text-brand-sage">
                    <x-heroicon-o-globe-alt class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('dply Edge') }}
                </div>
                <h1 class="mt-4 text-3xl font-semibold tracking-tight text-brand-ink sm:text-4xl">{{ __('Deploy an edge app') }}</h1>
                <p class="mt-3 max-w-prose text-sm leading-relaxed text-brand-moss sm:text-base">
                    {{ __('Connect a Git repository and dply builds static or SSG output, publishes to global edge delivery, and optionally redeploys on push.') }}
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-3 text-xs text-brand-moss">
                <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white/80 px-3 py-1.5 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                    <x-heroicon-o-bolt class="h-3.5 w-3.5 text-brand-gold" aria-hidden="true" />
                    {{ __('Instant HTTPS') }}
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white/80 px-3 py-1.5 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5 text-brand-sage" aria-hidden="true" />
                    {{ __('Preview branches') }}
                </span>
                <span class="inline-flex items-center gap-1.5 rounded-full border border-brand-ink/10 bg-white/80 px-3 py-1.5 dark:border-brand-mist/25 dark:bg-zinc-800/80">
                    <x-heroicon-o-cloud-arrow-up class="h-3.5 w-3.5 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                    {{ __('Deploy on push') }}
                </span>
            </div>
        </div>
    </header>

    @if ($fakeEdgeActive)
        <div data-testid="fake-edge-active-notice" class="mt-6 flex gap-3 rounded-2xl border border-sky-200/80 bg-sky-50/70 px-5 py-4 text-sm text-sky-950 dark:border-sky-900/40 dark:bg-sky-950/30 dark:text-sky-200">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-sky-100 text-sky-700 dark:bg-sky-900/50 dark:text-sky-300" aria-hidden="true">
                <x-heroicon-o-beaker class="h-5 w-5" />
            </span>
            <div>
                <p class="font-semibold">{{ __('Fake-edge mode is on') }}</p>
                <p class="mt-1 text-sky-900/80 dark:text-sky-200/80">{{ __('Builds land on the in-memory fake backend with synthetic hostnames. No Cloudflare credentials required in local/testing.') }}</p>
            </div>
        </div>
    @endif

    <form wire:submit="deploy" class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)] lg:items-start">
        <div class="min-w-0 space-y-6">
            {{-- 01 Identity --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-gold/15 text-sm font-bold text-brand-olive ring-1 ring-brand-gold/25 dark:bg-brand-gold/10 dark:text-brand-gold dark:ring-brand-gold/20">01</span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Name your app') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Used in the Edge index, site workspace, and preview URLs.') }}</p>
                        <div class="mt-4">
                            <x-input-label for="name" :value="__('App name')" />
                            <x-text-input id="name" wire:model="name" type="text" class="mt-1 block w-full" required placeholder="marketing-site" />
                            <x-input-error :messages="$errors->get('name')" class="mt-2" />
                        </div>
                    </div>
                </div>
            </section>

            {{-- 02 Source --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">02</span>
                    <div class="min-w-0 flex-1 space-y-4">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Connect Git') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('We clone, build, and publish from this repository on every deploy.') }}</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-3">
                            @if ($linkedSourceControlAccounts !== [])
                                <div role="radiogroup" aria-label="{{ __('Where to find the repo') }}" class="inline-flex rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-1 text-xs dark:border-brand-mist/20 dark:bg-zinc-800/60">
                                    <button
                                        type="button"
                                        role="radio"
                                        aria-checked="{{ $repo_source === 'connected' ? 'true' : 'false' }}"
                                        wire:click="$set('repo_source', 'connected')"
                                        @class([
                                            'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-semibold transition',
                                            'bg-white text-brand-ink shadow-sm dark:bg-zinc-700' => $repo_source === 'connected',
                                            'text-brand-moss hover:text-brand-ink' => $repo_source !== 'connected',
                                        ])
                                    >
                                        <x-heroicon-m-link class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Pick from connected account') }}
                                    </button>
                                    <button
                                        type="button"
                                        role="radio"
                                        aria-checked="{{ $repo_source === 'manual' ? 'true' : 'false' }}"
                                        wire:click="$set('repo_source', 'manual')"
                                        @class([
                                            'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 font-semibold transition',
                                            'bg-white text-brand-ink shadow-sm dark:bg-zinc-700' => $repo_source === 'manual',
                                            'text-brand-moss hover:text-brand-ink' => $repo_source !== 'manual',
                                        ])
                                    >
                                        <x-heroicon-m-pencil-square class="h-3.5 w-3.5" aria-hidden="true" />
                                        {{ __('Enter manually') }}
                                    </button>
                                </div>
                            @endif
                            <x-connect-provider-link>{{ __('Connect a provider') }} &rarr;</x-connect-provider-link>
                        </div>

                        @if ($linkedSourceControlAccounts === [])
                            <div class="rounded-xl border border-dashed border-brand-sage/30 bg-brand-sage/5 px-4 py-3 text-sm text-brand-moss dark:border-brand-sage/25 dark:bg-brand-sage/10">
                                <p class="font-medium text-brand-ink">{{ __('Link GitHub, GitLab, or Bitbucket to browse repositories here.') }}</p>
                                <p class="mt-1 text-xs">{{ __('You can still paste owner/repo manually, or connect an account to pick from a searchable list.') }}</p>
                            </div>
                        @endif

                        @if ($repo_source === 'connected' && $linkedSourceControlAccounts !== [])
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="source_control_account_id" :value="__('Account')" />
                                    <select id="source_control_account_id" wire:model.live="source_control_account_id" class="dply-input mt-1 block w-full">
                                        @foreach ($linkedSourceControlAccounts as $account)
                                            <option value="{{ $account['id'] }}">{{ $account['label'] ?? $account['id'] }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <x-input-label for="repository_selection" :value="__('Repository')" />
                                    <select id="repository_selection" wire:model.live="repository_selection" class="dply-input mt-1 block w-full" required>
                                        <option value="">{{ __('Select a repository…') }}</option>
                                        @foreach ($availableRepositories as $repository)
                                            <option value="{{ $repository['url'] }}">{{ $repository['label'] ?? $repository['name'] ?? $repository['url'] }}</option>
                                        @endforeach
                                    </select>
                                    @if ($availableRepositories === [])
                                        <p class="mt-1 text-xs text-brand-mist">{{ __('No repositories returned for this account. Check the token or enter the repo manually.') }}</p>
                                    @endif
                                </div>
                            </div>
                            @if ($repo !== '')
                                <p class="text-xs text-brand-moss">{{ __('Will deploy :repo on branch :branch.', ['repo' => $repo, 'branch' => $branch]) }}</p>
                            @endif
                            <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                        @else
                            <div class="grid gap-4 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,0.6fr)]">
                                <div>
                                    <x-input-label for="repo" :value="__('Git repository')" />
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                                            <x-heroicon-o-code-bracket class="h-4 w-4" />
                                        </span>
                                        <x-text-input id="repo" wire:model.live.debounce.500ms="repo" type="text" class="block w-full ps-10 font-mono text-sm" required placeholder="owner/repo" />
                                    </div>
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('owner/repo or a full GitHub URL') }}</p>
                                    <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                                </div>
                                <div>
                                    <x-input-label for="branch" :value="__('Branch')" />
                                    <div class="relative mt-1">
                                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                                            <x-heroicon-o-arrow-trending-up class="h-4 w-4" />
                                        </span>
                                        <x-text-input id="branch" wire:model.live.debounce.500ms="branch" type="text" class="block w-full ps-10 font-mono text-sm" required />
                                    </div>
                                    <x-input-error :messages="$errors->get('branch')" class="mt-2" />
                                </div>
                            </div>
                        @endif

                        @if ($repo_source === 'connected' && $linkedSourceControlAccounts !== [])
                            <div class="max-w-xs">
                                <x-input-label for="edge_branch_override" :value="__('Branch')" />
                                <div class="relative mt-1">
                                    <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist" aria-hidden="true">
                                        <x-heroicon-o-arrow-trending-up class="h-4 w-4" />
                                    </span>
                                    <x-text-input id="edge_branch_override" wire:model.live.debounce.500ms="branch" type="text" class="block w-full ps-10 font-mono text-sm" required />
                                </div>
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Defaults to the repository\'s default branch — change if you deploy from another branch.') }}</p>
                                <x-input-error :messages="$errors->get('branch')" class="mt-2" />
                            </div>
                        @endif
                    </div>
                </div>
            </section>

            {{-- 03 Detect --}}
            <section class="rounded-2xl border-2 border-brand-sage/20 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-sage/25 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">03</span>
                    <div class="min-w-0 flex-1 space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Detect build settings') }}</h2>
                                <p class="mt-0.5 text-sm text-brand-moss">{{ __('We scan the repo automatically when you paste a complete owner/name or URL. Use Detect runtime to retry.') }}</p>
                            </div>
                            <button
                                type="button"
                                wire:click="detectFromRepository"
                                wire:loading.attr="disabled"
                                wire:target="detectFromRepository"
                                class="inline-flex shrink-0 items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-50 dark:shadow-none"
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

            {{-- 04 Build --}}
            <section
                x-data="{ advancedOpen: @js($build_command !== '' || $output_dir !== '' && $output_dir !== 'dist') }"
                class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900"
            >
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">04</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Build output') }}</h2>
                                <p class="mt-0.5 text-sm text-brand-moss">{{ __('Override only when detection misses your setup.') }}</p>
                            </div>
                            <button
                                type="button"
                                x-on:click="advancedOpen = ! advancedOpen"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/10 bg-brand-cream/50 px-3 py-1.5 text-xs font-semibold text-brand-moss transition-colors hover:border-brand-sage/40 hover:text-brand-forest dark:border-brand-mist/25 dark:bg-zinc-800 dark:hover:text-brand-sage"
                            >
                                <span x-text="advancedOpen ? '{{ __('Hide overrides') }}' : '{{ __('Show overrides') }}'"></span>
                                <x-heroicon-m-chevron-down class="h-3.5 w-3.5 transition-transform" x-bind:class="advancedOpen ? 'rotate-180' : ''" aria-hidden="true" />
                            </button>
                        </div>
                        <div x-show="advancedOpen" x-collapse class="mt-4 space-y-4">
                            <div>
                                <x-input-label for="build_command" :value="__('Build command override')" />
                                <x-text-input id="build_command" wire:model="build_command" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="npm ci && npm run build" />
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Leave blank to use the detected command or the default npm build.') }}</p>
                                <x-input-error :messages="$errors->get('build_command')" class="mt-2" />
                            </div>
                            <div>
                                <x-input-label for="output_dir" :value="__('Output directory')" />
                                <x-text-input id="output_dir" wire:model="output_dir" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="dist" />
                                <p class="mt-1 text-xs text-brand-mist">{{ __('Folder containing the static assets after the build (e.g. dist, out, .output/public).') }}</p>
                                <x-input-error :messages="$errors->get('output_dir')" class="mt-2" />
                            </div>
                        </div>
                        <p x-show="! advancedOpen" class="mt-3 rounded-xl border border-dashed border-brand-ink/12 bg-brand-cream/30 px-4 py-3 text-xs text-brand-moss dark:border-brand-mist/20 dark:bg-zinc-800/40">
                            {{ __('Using detected or default build settings. Open overrides to customize the command and output folder.') }}
                        </p>

                        <div class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-cream/25 p-4 dark:border-brand-mist/20 dark:bg-zinc-800/40">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Delivery mode') }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Static sites serve everything from Edge. Hybrid keeps static assets on Edge and proxies dynamic routes to a long-running origin (dply Cloud or external URL).') }}</p>
                            <div class="mt-3 flex flex-wrap gap-3">
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input type="radio" wire:model.live="runtime_mode" value="static" class="text-brand-sage focus:ring-brand-sage/40" />
                                    <span>{{ __('Static / SSG') }}</span>
                                </label>
                                <label class="inline-flex items-center gap-2 text-sm">
                                    <input type="radio" wire:model.live="runtime_mode" value="hybrid" class="text-brand-sage focus:ring-brand-sage/40" />
                                    <span>{{ __('Hybrid (static + origin SSR)') }}</span>
                                </label>
                            </div>
                            @if ($runtime_mode === 'hybrid')
                                <div class="mt-4">
                                    <x-input-label for="origin_url" :value="__('SSR origin URL')" />
                                    <x-text-input id="origin_url" wire:model="origin_url" type="url" class="mt-1 block w-full font-mono text-sm" placeholder="https://my-app.dply.cloud" required />
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('Container or external URL that handles server-rendered routes (e.g. a dply Cloud app).') }}</p>
                                    <x-input-error :messages="$errors->get('origin_url')" class="mt-2" />
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </section>

            {{-- 05 Delivery --}}
            <section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-7 dark:border-brand-mist/20 dark:bg-zinc-900">
                <div class="flex items-start gap-4">
                    <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-sm font-bold text-brand-forest ring-1 ring-brand-sage/25 dark:bg-brand-sage/15 dark:text-brand-sage dark:ring-brand-sage/30">05</span>
                    <div class="min-w-0 flex-1 space-y-5">
                        <div>
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Edge delivery') }}</h2>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Choose whether dply hosts delivery on our Cloudflare stack or deploys into your account.') }}</p>
                        </div>

                        <div role="radiogroup" aria-label="{{ __('Edge delivery mode') }}" class="grid gap-3 sm:grid-cols-2">
                            <label @class([
                                'relative flex cursor-pointer rounded-xl border p-4 transition-colors',
                                'border-brand-sage/40 bg-brand-sage/5 dark:border-brand-sage/35 dark:bg-brand-sage/10' => $delivery_mode === 'managed',
                                'border-brand-ink/10 bg-brand-cream/30 hover:border-brand-sage/30 dark:border-brand-mist/20 dark:bg-zinc-800/40' => $delivery_mode !== 'managed',
                            ])>
                                <input type="radio" wire:model.live="delivery_mode" value="managed" class="mt-0.5 text-brand-sage focus:ring-brand-sage/40" />
                                <span class="ms-3">
                                    <span class="block text-sm font-semibold text-brand-ink">{{ __('Dply Edge (managed)') }}</span>
                                    <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('Default — global delivery on dply\'s Cloudflare account. Usage billed through your dply plan.') }}</span>
                                </span>
                            </label>
                            <label @class([
                                'relative flex cursor-pointer rounded-xl border p-4 transition-colors',
                                'border-brand-sage/40 bg-brand-sage/5 dark:border-brand-sage/35 dark:bg-brand-sage/10' => $delivery_mode === 'byo',
                                'border-brand-ink/10 bg-brand-cream/30 hover:border-brand-sage/30 dark:border-brand-mist/20 dark:bg-zinc-800/40' => $delivery_mode !== 'byo',
                            ])>
                                <input type="radio" wire:model.live="delivery_mode" value="byo" class="mt-0.5 text-brand-sage focus:ring-brand-sage/40" />
                                <span class="ms-3">
                                    <span class="block text-sm font-semibold text-brand-ink">{{ __('Your Cloudflare account') }}</span>
                                    <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('Deploy Worker, KV, and R2 in your Cloudflare account. You pay Cloudflare directly for delivery usage.') }}</span>
                                </span>
                            </label>
                        </div>

                        @if ($delivery_mode === 'byo')
                            <div class="rounded-xl border border-brand-sage/25 bg-brand-sage/5 px-4 py-4 dark:border-brand-sage/20 dark:bg-brand-sage/10">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <x-input-label for="edge_provider_credential_id" :value="__('Cloudflare account')" />
                                    <x-add-provider-credential-link provider="cloudflare" class="text-brand-forest dark:text-brand-sage">
                                        {{ __('Connect Cloudflare') }} &rarr;
                                    </x-add-provider-credential-link>
                                </div>
                                <select id="edge_provider_credential_id" wire:model="edge_provider_credential_id" class="dply-input mt-2 block w-full" required>
                                    <option value="">{{ __('Select a connected account…') }}</option>
                                    @foreach ($cloudflareCredentials as $credential)
                                        <option value="{{ $credential->id }}">{{ $credential->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('edge_provider_credential_id')" class="mt-2" />
                                @if ($cloudflareCredentials->isEmpty())
                                    <p class="mt-2 text-xs text-brand-moss">{{ __('Connect Cloudflare with Workers, KV, and R2 permissions, then bootstrap Edge infra from the CLI.') }}</p>
                                @endif
                                <p class="mt-3 text-xs leading-relaxed text-brand-moss">
                                    {{ __('Required token scopes: Workers Scripts Edit, Workers KV Storage Edit, Workers R2 Storage Edit. After connecting, run') }}
                                    <code class="rounded bg-white/80 px-1 py-0.5 font-mono text-[11px] dark:bg-zinc-900/80">php artisan dply:edge:bootstrap-org &lt;credential&gt; --account-id=... --zone-name=...</code>
                                </p>
                            </div>
                        @endif

                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="group relative flex cursor-pointer rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4 transition-colors has-[:checked]:border-brand-sage/40 has-[:checked]:bg-brand-sage/5 hover:border-brand-sage/30 dark:border-brand-mist/20 dark:bg-zinc-800/40 dark:has-[:checked]:border-brand-sage/35 dark:has-[:checked]:bg-brand-sage/10">
                                <input type="checkbox" wire:model="spa_fallback" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40 dark:border-brand-mist/30" />
                                <span class="ms-3">
                                    <span class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                        <x-heroicon-o-arrows-right-left class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                                        {{ __('SPA fallback') }}
                                    </span>
                                    <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('Serve index.html for unknown paths — typical for client-side routed SPAs.') }}</span>
                                </span>
                            </label>
                            <label class="group relative flex cursor-pointer rounded-xl border border-brand-ink/10 bg-brand-cream/30 p-4 transition-colors has-[:checked]:border-brand-sage/40 has-[:checked]:bg-brand-sage/5 hover:border-brand-sage/30 dark:border-brand-mist/20 dark:bg-zinc-800/40 dark:has-[:checked]:border-brand-sage/35 dark:has-[:checked]:bg-brand-sage/10">
                                <input type="checkbox" wire:model="deploy_on_push" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage shadow-sm focus:ring-brand-sage/40 dark:border-brand-mist/30" />
                                <span class="ms-3">
                                    <span class="flex items-center gap-2 text-sm font-semibold text-brand-ink">
                                        <x-heroicon-o-bolt class="h-4 w-4 text-brand-gold" aria-hidden="true" />
                                        {{ __('Deploy on push') }}
                                    </span>
                                    <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('When a GitHub webhook is configured, pushes to the production branch trigger a rebuild.') }}</span>
                                </span>
                            </label>
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex flex-col-reverse gap-3 border-t border-brand-ink/8 pt-6 sm:flex-row sm:items-center sm:justify-between dark:border-brand-mist/15">
                <a href="{{ route('edge.index') }}" wire:navigate class="inline-flex items-center justify-center gap-1.5 text-sm font-medium text-brand-moss transition-colors hover:text-brand-ink">
                    <x-heroicon-m-arrow-left class="h-4 w-4" aria-hidden="true" />
                    {{ __('Back to Edge sites') }}
                </a>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="deploy" class="w-full sm:w-auto">
                    <span wire:loading.remove wire:target="deploy" class="inline-flex items-center gap-2">
                        <x-heroicon-o-rocket-launch class="h-4 w-4" aria-hidden="true" />
                        {{ __('Deploy edge app') }}
                    </span>
                    <span wire:loading wire:target="deploy" class="inline-flex items-center justify-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Queueing…') }}
                    </span>
                </x-primary-button>
            </div>
        </div>

        @include('livewire.edge.partials.create-sidebar', ['edgeFee' => $edgeFee])
    </form>

    <x-connect-provider-modal />
    <livewire:credentials.add-provider-credential-modal capability="cdn" default-provider="cloudflare" />
</div>
