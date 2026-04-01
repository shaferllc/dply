@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
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
        <div class="p-6 sm:p-8">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('New site') }}</h2>
            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                {{ __('Enter a primary domain to get started. Stack, paths, and PHP options are available in advanced settings.') }}
            </p>
            <form id="quick-site-form" wire:submit="startQuickSite" class="mt-6">
                <x-input-label for="quick_site_hostname" value="{{ __('Domain') }}" />
                <input
                    id="quick_site_hostname"
                    type="text"
                    wire:model="quick_site_hostname"
                    placeholder="{{ __('e.g. app.example.com') }}"
                    autocomplete="off"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    @disabled(! $this->canAddSite)
                />
                <x-input-error :messages="$errors->get('quick_site_hostname')" class="mt-2" />
            </form>
        </div>
        <div class="flex flex-col-reverse items-stretch justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:flex-row sm:items-center sm:justify-end">
            <a
                href="{{ route('sites.create', $server) }}"
                wire:navigate
                class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                {{ __('Advanced settings') }}
            </a>
            @if ($this->canAddSite)
                <x-primary-button type="submit" form="quick-site-form" class="justify-center">{{ __('Add site') }}</x-primary-button>
            @else
                <span
                    class="inline-flex cursor-not-allowed items-center justify-center rounded-lg bg-brand-mist/40 px-4 py-2.5 text-sm font-semibold text-brand-moss"
                    title="{{ __('Requires owner/admin and room under your plan’s site limit—or upgrade.') }}"
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
                                    {{ $s->php_fpm_user ?: $server->ssh_user }}
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
    </x-slot>
</x-server-workspace-layout>
