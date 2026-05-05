@php
    $card = 'dply-card overflow-hidden';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="sites"
    :title="__('Sites')"
    :description="__('Manage sites, databases, automation, and deploy tools for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <div class="{{ $card }}">
        <div class="flex flex-col gap-4 p-6 sm:flex-row sm:items-center sm:justify-between sm:p-8">
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('New site') }}</h2>
                <p class="mt-1 text-sm text-brand-moss leading-relaxed">
                    {{ __('Add a domain to get started. Stack, paths, and PHP options are available in advanced settings.') }}
                </p>
                @if (! $this->canAddSite && $this->addSiteBlockedReason !== '')
                    <p class="mt-3 inline-flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-snug text-amber-900">
                        <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                        <span>{{ $this->addSiteBlockedReason }}</span>
                    </p>
                @endif
            </div>
            @if ($this->canAddSite)
                <x-primary-button type="button" wire:click="openAddSiteModal" class="justify-center">{{ __('Add site') }}</x-primary-button>
            @else
                <span
                    class="inline-flex cursor-not-allowed items-center justify-center rounded-lg bg-brand-mist/40 px-4 py-2.5 text-sm font-semibold text-brand-moss"
                    title="{{ $this->addSiteBlockedReason }}"
                >
                    {{ __('Add site') }}
                </span>
            @endif
        </div>
    </div>

    <div class="{{ $card }}">
        <div class="flex items-center justify-between border-b border-brand-ink/10 px-5 py-3 sm:px-8">
            <h2 class="text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Sites') }}</h2>
            <x-heroicon-o-funnel class="h-4 w-4 text-brand-mist" aria-hidden="true" />
        </div>
        @if ($server->sites->isEmpty())
            <p class="px-5 py-10 sm:px-8 text-center text-sm text-brand-moss">{{ __('No sites yet. Add a site to manage web server config, SSL, Git deploys, and environment files.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($server->sites as $s)
                    @php
                        $primaryDomain = $s->domains->sortByDesc('is_primary')->first();
                        $displayHost = $primaryDomain?->hostname ?? $s->name;
                        $statusOk = $s->isReadyForTraffic();
                        $sslOn = $s->ssl_status === \App\Models\Site::SSL_ACTIVE;
                        $gitRef = $s->git_repository_url;
                        $gitShort = $gitRef ? (preg_match('#([^/:]+/[^/]+?)(?:\.git)?$#', $gitRef, $m) ? $m[1] : \Illuminate\Support\Str::limit($gitRef, 40)) : null;
                    @endphp
                    <li class="relative flex">
                        <span
                            @class([
                                'absolute bottom-0 left-0 top-0 w-1',
                                'bg-brand-forest' => $statusOk,
                                'bg-brand-gold' => ! $statusOk && $s->status !== \App\Models\Site::STATUS_ERROR,
                                'bg-brand-rust' => $s->status === \App\Models\Site::STATUS_ERROR,
                            ])
                            aria-hidden="true"
                        ></span>
                        <div class="min-w-0 flex-1 py-5 pl-5 pr-5 sm:pl-8 sm:pr-8">
                            <div class="flex flex-wrap items-center gap-2">
                                <a
                                    href="{{ route('sites.show', [$server, $s]) }}"
                                    wire:navigate
                                    class="text-base font-semibold text-brand-ink hover:text-brand-sage transition-colors"
                                >{{ $displayHost }}</a>
                                @if ($sslOn)
                                    <x-heroicon-s-lock-closed class="h-4 w-4 text-brand-forest" title="{{ __('SSL active') }}" />
                                @endif
                                @if (filter_var($s->meta['debug'] ?? false, FILTER_VALIDATE_BOOLEAN))
                                    <span class="rounded-md bg-brand-sand px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-olive">{{ __('Debug mode on') }}</span>
                                @endif
                            </div>
                            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-brand-moss">
                                @if ($gitShort)
                                    <span class="inline-flex items-center gap-1">
                                        <x-heroicon-o-code-bracket class="h-3.5 w-3.5 opacity-80" />
                                        {{ $gitShort }}
                                        @if ($s->git_branch)
                                            <span class="text-brand-mist">({{ $s->git_branch }})</span>
                                        @endif
                                    </span>
                                @endif
                                <span class="inline-flex items-center gap-1">
                                    <x-heroicon-o-user class="h-3.5 w-3.5 opacity-80" />
                                    {{ $s->effectiveSystemUser($server) }}
                                </span>
                                @if ($s->type?->value === 'php' && $s->php_version)
                                    <span class="inline-flex items-center gap-1 font-mono text-brand-ink/80">PHP {{ $s->php_version }}</span>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])

        @if ($this->supportsQuickAdd)
            <x-modal
                name="add-site-modal"
                :show="$showAddSiteModal"
                maxWidth="lg"
                overlayClass="bg-brand-ink/40"
                focusable
            >
                <form wire:submit="addSite" x-data="{ showAdvanced: false }">
                    <div class="border-b border-brand-ink/10 px-6 py-5">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New site') }}</p>
                        <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add a site to :server', ['server' => $server->name]) }}</h2>
                        <p class="mt-2 text-sm leading-6 text-brand-moss">
                            {{ __('Enter a primary domain. Stack, paths, and PHP options are available below.') }}
                        </p>
                    </div>

                    <div class="space-y-5 px-6 py-6">
                        <div>
                            <x-input-label for="add-site-hostname" :value="__('Primary domain')" />
                            <x-text-input
                                id="add-site-hostname"
                                wire:model.live.debounce.300ms="form.primary_hostname"
                                type="text"
                                class="mt-1 block w-full font-mono text-sm"
                                placeholder="app.example.com"
                                required
                                autocomplete="off"
                            />
                            <x-input-error :messages="$errors->get('form.primary_hostname')" class="mt-1" />
                        </div>

                        <div class="border-t border-brand-ink/10 pt-4">
                            <button
                                type="button"
                                x-on:click="showAdvanced = !showAdvanced"
                                class="flex w-full items-center justify-between text-sm font-semibold text-brand-ink hover:text-brand-sage"
                                x-bind:aria-expanded="showAdvanced"
                            >
                                <span>{{ __('Advanced settings') }}</span>
                                <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform" x-bind:class="showAdvanced ? 'rotate-180' : ''" />
                            </button>

                            <div x-show="showAdvanced" x-collapse class="mt-5 space-y-5">
                                <div>
                                    <x-input-label for="add-site-name" :value="__('Site name')" />
                                    <x-text-input
                                        id="add-site-name"
                                        wire:model="form.name"
                                        type="text"
                                        class="mt-1 block w-full"
                                        autocomplete="off"
                                    />
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('Used for the slug and deploy path. Auto-derived from the domain.') }}</p>
                                    <x-input-error :messages="$errors->get('form.name')" class="mt-1" />
                                </div>

                                <div class="grid gap-5 sm:grid-cols-2">
                                    <div>
                                        <x-input-label for="add-site-doc-root" :value="__('Web directory')" />
                                        <x-text-input
                                            id="add-site-doc-root"
                                            wire:model.blur="form.document_root"
                                            type="text"
                                            class="mt-1 block w-full font-mono text-sm"
                                            required
                                        />
                                        <x-input-error :messages="$errors->get('form.document_root')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label for="add-site-deploy-path" :value="__('Project directory')" />
                                        <x-text-input
                                            id="add-site-deploy-path"
                                            wire:model.blur="form.repository_path"
                                            type="text"
                                            class="mt-1 block w-full font-mono text-sm"
                                        />
                                        <x-input-error :messages="$errors->get('form.repository_path')" class="mt-1" />
                                    </div>
                                </div>

                                <div>
                                    <x-input-label for="add-site-framework" :value="__('Project type')" />
                                    <select
                                        id="add-site-framework"
                                        wire:model.live="form.framework"
                                        class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                    >
                                        <option value="">{{ __('None (Static HTML or PHP)') }}</option>
                                        <option value="laravel">Laravel</option>
                                        <option value="nodejs">NodeJS</option>
                                        <option value="statamic">Statamic</option>
                                        <option value="craft">Craft CMS</option>
                                        <option value="symfony">Symfony</option>
                                        <option value="wordpress">WordPress</option>
                                        <option value="october">OctoberCMS</option>
                                        <option value="cakephp3">CakePHP 3</option>
                                    </select>
                                    <p class="mt-1 text-xs text-brand-mist">{{ __('PHP version and runtime details are detected from the repository when the first deploy clones the project.') }}</p>
                                    <x-input-error :messages="$errors->get('form.framework')" class="mt-1" />
                                </div>

                                <div>
                                    <x-input-label for="add-site-template" :value="__('Webserver template')" />
                                    <select
                                        id="add-site-template"
                                        wire:model="form.webserver_template"
                                        class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                    >
                                        <option value="default">{{ __('Default template') }}</option>
                                    </select>
                                </div>

                                @if ($form->type === 'node')
                                    <div>
                                        <x-input-label for="add-site-port" :value="__('App listens on (localhost)')" />
                                        <x-text-input
                                            id="add-site-port"
                                            type="number"
                                            wire:model="form.app_port"
                                            class="mt-1 block w-32"
                                        />
                                        <p class="mt-1 text-xs text-brand-mist">{{ __('Nginx will proxy requests to this port.') }}</p>
                                    </div>
                                @endif

                                <div class="space-y-3 border-t border-brand-ink/10 pt-4">
                                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                                        <input
                                            type="checkbox"
                                            wire:model="form.create_system_user"
                                            class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                                        />
                                        <span>
                                            <span class="font-medium">{{ __('Create system user') }}</span>
                                            <span class="block text-xs text-brand-mist">{{ __('Creates a system user with a random generated name dedicated to this site.') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                                        <input
                                            type="checkbox"
                                            wire:model="form.create_staging_site"
                                            class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                                        />
                                        <span>
                                            <span class="font-medium">{{ __('Create staging site') }}</span>
                                            <span class="block text-xs text-brand-mist">{{ __('Creates an extra site for development. After development is done you can push the code over to the main site.') }}</span>
                                        </span>
                                    </label>
                                    <label class="flex items-start gap-3 text-sm text-brand-ink">
                                        <input
                                            type="checkbox"
                                            wire:model="form.use_as_redirect_domain"
                                            class="mt-0.5 rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                                        />
                                        <span>
                                            <span class="font-medium">{{ __('Use as redirect domain') }}</span>
                                            <span class="block text-xs text-brand-mist">{{ __('Redirects this whole domain to another domain.') }}</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                        <x-secondary-button type="button" wire:click="closeAddSiteModal">
                            {{ __('Cancel') }}
                        </x-secondary-button>
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addSite">
                            <span wire:loading.remove wire:target="addSite">{{ __('Add site') }}</span>
                            <span wire:loading wire:target="addSite" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" />
                                {{ __('Adding…') }}
                            </span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        @endif
    </x-slot>
</x-server-workspace-layout>
