<div>
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        @include('livewire.sites.partials.workspace-breadcrumb-bar', [
            'server' => $server,
            'site' => $site,
            'currentLabel' => __('Set up site'),
            'currentIcon' => 'wrench-screwdriver',
        ])

        <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
            @include('livewire.sites.settings.partials.sidebar')

            <main class="min-w-0 space-y-6 lg:col-span-9">
                <div class="flex items-start justify-between gap-4">
                    <x-page-header
                        :title="__('Set up your site')"
                        :description="__('Configure what :name needs, then deploy. Your site stays live on its preview URL the whole time.', ['name' => $site->name])"
                        :show-documentation="false"
                        flush
                        compact
                    />
                    <button type="button" wire:click="configureLater"
                        class="shrink-0 rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-medium text-brand-moss transition-colors hover:bg-brand-sand/40 hover:text-brand-ink">
                        {{ __("I'll configure later") }}
                    </button>
                </div>

                @if ($site->isPreflightScanning())
                    {{-- Analyzing: pre-flight clone + scan in flight. --}}
                    <div wire:poll.2s.visible="pollPreflight"
                        class="rounded-2xl border border-brand-ink/10 bg-white/80 px-8 py-14 text-center shadow-sm">
                        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-brand-sage/12 text-brand-forest">
                            <x-heroicon-o-magnifying-glass class="h-6 w-6 animate-pulse" />
                        </div>
                        <h2 class="mt-5 text-lg font-semibold text-brand-ink">{{ __('Analyzing your repository…') }}</h2>
                        <p class="mx-auto mt-2 max-w-md text-sm text-brand-moss">
                            {{ __('Reading the code to detect the environment variables and resources it needs. This usually takes a few seconds.') }}
                        </p>
                    </div>
                @else
                    @php
                        $missing = $this->missingRequired();
                        $planFields = $this->planEnvFields();
                        $resourceGroups = $this->resourceGroups();
                        $envComplete = collect($planFields)->every(fn ($f) => ! $f['required'] || trim((string) ($env[$f['key']] ?? '')) !== '');
                        $resourcesComplete = collect($resourceGroups)->every(fn ($g) => $g['satisfied']);
                        $steps = [
                            ['id' => 'environment', 'n' => 1, 'label' => __('Environment'), 'done' => $envComplete],
                            ['id' => 'resources', 'n' => 2, 'label' => __('Resources'), 'done' => $resourcesComplete],
                            ['id' => 'review', 'n' => 3, 'label' => __('Review & deploy'), 'done' => false],
                        ];
                    @endphp

                    @if ($site->setupScanFailed())
                        <div class="rounded-2xl border border-brand-gold/40 bg-brand-gold/10 px-5 py-4">
                            <div class="flex items-start gap-3">
                                <x-heroicon-o-exclamation-triangle class="mt-0.5 h-5 w-5 shrink-0 text-brand-rust" />
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-semibold text-brand-ink">{{ __("Couldn't read your repository") }}</p>
                                    <p class="mt-1 text-sm text-brand-moss">
                                        @switch($site->setupScanFailureReason())
                                            @case('auth')
                                                {{ __('Access was denied — this looks like a private repository. Connect a source-control account or check the deploy credentials, then re-scan.') }}
                                                @break
                                            @case('not_found')
                                                {{ __('The repository could not be found. Double-check the URL and branch, then re-scan.') }}
                                                @break
                                            @case('network')
                                                {{ __('We could not reach the git host (network/timeout). Re-scan to try again.') }}
                                                @break
                                            @case('branch')
                                                {{ __('The branch could not be found in the repository. Check the branch name, then re-scan.') }}
                                                @break
                                            @default
                                                {{ __('Something went wrong reading the repository. You can still enter variables manually, or re-scan.') }}
                                        @endswitch
                                    </p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <button type="button" wire:click="rescan"
                                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-medium text-brand-cream hover:bg-brand-forest">
                                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" /> {{ __('Re-scan') }}
                                        </button>
                                        <a href="{{ route('sites.repository', [$server, $site]) }}" wire:navigate
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                                            {{ __('Repository settings') }}
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Stepper --}}
                    <nav class="flex items-center gap-2">
                        @foreach ($steps as $s)
                            <button type="button" wire:click="goToStep('{{ $s['id'] }}')" @class([
                                'flex flex-1 items-center gap-3 rounded-xl border px-4 py-3 text-left transition-colors',
                                'border-brand-forest bg-white shadow-sm ring-1 ring-brand-sage/30' => $step === $s['id'],
                                'border-brand-ink/10 bg-white/60 hover:border-brand-ink/20' => $step !== $s['id'],
                            ])>
                                <span @class([
                                    'flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold',
                                    'bg-brand-forest text-brand-cream' => $step === $s['id'],
                                    'bg-brand-sage/15 text-brand-forest' => $step !== $s['id'] && $s['done'],
                                    'bg-brand-ink/[0.06] text-brand-mist' => $step !== $s['id'] && ! $s['done'],
                                ])>
                                    @if ($s['done'] && $step !== $s['id'])
                                        <x-heroicon-s-check class="h-4 w-4" />
                                    @else
                                        {{ $s['n'] }}
                                    @endif
                                </span>
                                <span class="text-sm font-medium {{ $step === $s['id'] ? 'text-brand-ink' : 'text-brand-moss' }}">{{ $s['label'] }}</span>
                            </button>
                        @endforeach
                    </nav>

                    {{-- Step body --}}
                    <div class="rounded-2xl border border-brand-ink/10 bg-white/80 p-6 shadow-sm">
                        @if ($step === 'environment')
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Environment variables') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Pre-filled from your repository. Required variables must be set before you can deploy.') }}</p>

                            @error('env')<p class="mt-3 rounded-lg bg-brand-rust/10 px-3 py-2 text-sm text-brand-rust">{{ $message }}</p>@enderror

                            @if (empty($planFields))
                                <p class="mt-5 rounded-lg bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">{{ __('No application variables were detected. You can move on.') }}</p>
                            @else
                                <div class="mt-5 space-y-4">
                                    @foreach ($planFields as $field)
                                        <div>
                                            <label class="flex items-center gap-2 text-sm font-medium text-brand-ink">
                                                <code class="rounded bg-brand-ink/[0.05] px-1.5 py-0.5 text-xs">{{ $field['key'] }}</code>
                                                @if ($field['required'])
                                                    <span class="text-[10px] font-bold uppercase tracking-wide text-brand-rust">{{ __('Required') }}</span>
                                                @endif
                                            </label>
                                            <input type="text" wire:model="env.{{ $field['key'] }}"
                                                @if ($field['example']) placeholder="{{ $field['example'] }}" @endif
                                                class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono text-brand-ink focus:border-brand-forest focus:ring-brand-forest" />
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="mt-6 flex justify-end">
                                <button type="button" wire:click="saveEnvironment"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-medium text-brand-cream hover:bg-brand-forest">
                                    {{ __('Save & continue') }} <x-heroicon-o-arrow-right class="h-4 w-4" />
                                </button>
                            </div>

                        @elseif ($step === 'resources')
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Resources') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Databases, cache, queue and mail your app references. Create what you need — or skip if you point at something external.') }}</p>

                            @if (empty($resourceGroups))
                                <p class="mt-5 rounded-lg bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">{{ __('No resources were detected from your environment. You can move on.') }}</p>
                            @else
                                <div class="mt-5 space-y-4">
                                    @foreach ($resourceGroups as $group)
                                        <div class="rounded-xl border border-brand-ink/10 p-4">
                                            <div class="flex items-center justify-between gap-3">
                                                <div class="flex items-center gap-2">
                                                    <span class="text-sm font-semibold text-brand-ink">{{ $group['label'] }}</span>
                                                    @if ($group['satisfied'])
                                                        <span class="inline-flex items-center gap-1 rounded-full bg-brand-sage/15 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-forest"><x-heroicon-s-check class="h-3 w-3" /> {{ __('Connected') }}</span>
                                                    @else
                                                        <span class="rounded-full bg-brand-gold/20 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-brand-rust">{{ __('Needs setup') }}</span>
                                                    @endif
                                                </div>
                                                <span class="font-mono text-[11px] text-brand-mist">{{ implode(' · ', $group['keys']) }}</span>
                                            </div>

                                            @if ($group['family'] === 'database' && ! $group['satisfied'])
                                                <div class="mt-3 flex flex-wrap items-end gap-3">
                                                    <div>
                                                        <label class="block text-xs font-medium text-brand-moss">{{ __('Name') }}</label>
                                                        <input type="text" wire:model="dbName" class="mt-1 w-44 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-mono focus:border-brand-forest focus:ring-brand-forest" />
                                                        @error('dbName')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-medium text-brand-moss">{{ __('Engine') }}</label>
                                                        <select wire:model="dbEngine" class="mt-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm focus:border-brand-forest focus:ring-brand-forest">
                                                            @foreach ($this->installedDbEngines() as $engine)
                                                                <option value="{{ $engine['value'] }}">{{ $engine['label'] }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <button type="button" wire:click="createDatabase" wire:loading.attr="disabled"
                                                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-2 text-sm font-medium text-brand-cream hover:bg-brand-forest/90 disabled:opacity-60">
                                                        <span wire:loading.remove wire:target="createDatabase">{{ __('Create & connect') }}</span>
                                                        <span wire:loading wire:target="createDatabase">{{ __('Creating…') }}</span>
                                                    </button>
                                                </div>
                                                @if (empty($this->installedDbEngines()))
                                                    <p class="mt-2 text-xs text-brand-rust">{{ __('No provisionable database engine is installed on this server. Install one from the server Databases tab first.') }}</p>
                                                @endif
                                            @elseif (! $group['satisfied'])
                                                <div class="mt-3 space-y-2">
                                                    <p class="text-xs text-brand-moss">{{ __('Point these at your host. Leave blank to use the app default.') }}</p>
                                                    @foreach ($group['keys'] as $rkey)
                                                        <div class="flex items-center gap-2">
                                                            <code class="w-40 shrink-0 text-xs text-brand-ink">{{ $rkey }}</code>
                                                            <input type="text" wire:model="env.{{ $rkey }}" class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-sm font-mono focus:border-brand-forest focus:ring-brand-forest" />
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="mt-6 flex items-center justify-between">
                                <button type="button" wire:click="goToStep('environment')" class="text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('← Back') }}</button>
                                <button type="button" wire:click="saveResourcesAndReview"
                                    class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-medium text-brand-cream hover:bg-brand-forest">
                                    {{ __('Continue to review') }} <x-heroicon-o-arrow-right class="h-4 w-4" />
                                </button>
                            </div>

                        @else
                            {{-- Review & deploy --}}
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Review & deploy') }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">{{ __('Confirm and run the first deploy. Your environment is written to the server as the deploy runs.') }}</p>

                            <dl class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div class="rounded-xl border border-brand-ink/10 p-4">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Repository') }}</dt>
                                    <dd class="mt-1 truncate font-mono text-sm text-brand-ink">{{ $site->git_repository_url }}</dd>
                                    <dd class="text-xs text-brand-moss">{{ __('Branch') }}: {{ $site->git_branch }}</dd>
                                </div>
                                <div class="rounded-xl border border-brand-ink/10 p-4">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-brand-mist">{{ __('Document root') }}</dt>
                                    <dd class="mt-1 font-mono text-sm text-brand-ink">{{ $site->document_root ?: '/' }}</dd>
                                    <dd class="text-xs text-brand-moss">{{ __('Runtime') }}: {{ $site->runtime }}{{ $site->runtime_version ? ' '.$site->runtime_version : '' }}</dd>
                                </div>
                            </dl>

                            @error('deploy')<p class="mt-4 rounded-lg bg-brand-rust/10 px-3 py-2 text-sm text-brand-rust">{{ $message }}</p>@enderror

                            @if (! empty($missing))
                                <div class="mt-4 rounded-xl border border-brand-gold/40 bg-brand-gold/10 p-4">
                                    <p class="text-sm font-semibold text-brand-ink">{{ __(':count required variable(s) still unset', ['count' => count($missing)]) }}</p>
                                    <p class="mt-1 font-mono text-xs text-brand-moss">{{ implode(', ', $missing) }}</p>
                                    <button type="button" wire:click="goToStep('environment')" class="mt-2 text-xs font-medium text-brand-rust hover:underline">{{ __('← Finish them') }}</button>
                                </div>
                            @endif

                            <div class="mt-6 flex items-center justify-between">
                                <button type="button" wire:click="goToStep('resources')" class="text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('← Back') }}</button>
                                <button type="button" wire:click="finishAndDeploy" @disabled(! empty($missing)) wire:loading.attr="disabled"
                                    @class([
                                        'inline-flex items-center gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold transition-colors',
                                        'bg-brand-forest text-brand-cream hover:bg-brand-forest/90' => empty($missing),
                                        'cursor-not-allowed bg-brand-ink/10 text-brand-mist' => ! empty($missing),
                                    ])>
                                    <x-heroicon-o-rocket-launch class="h-4 w-4" />
                                    <span wire:loading.remove wire:target="finishAndDeploy">{{ __('Deploy now') }}</span>
                                    <span wire:loading wire:target="finishAndDeploy">{{ __('Starting deploy…') }}</span>
                                </button>
                            </div>
                        @endif
                    </div>
                @endif
            </main>
        </div>
    </div>
</div>
