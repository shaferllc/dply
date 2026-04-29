@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
    $canEdit = auth()->user()->can('update', $site);
@endphp

<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <nav class="mb-6 text-sm text-slate-500" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="transition-colors hover:text-slate-900">{{ __('Dashboard') }}</a></li>
            <li class="text-slate-400" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="transition-colors hover:text-slate-900">{{ __('Servers') }}</a></li>
            <li class="text-slate-400" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="transition-colors hover:text-slate-900">{{ $server->name }}</a></li>
            <li class="text-slate-400" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="transition-colors hover:text-slate-900">{{ $site->name }}</a></li>
            <li class="text-slate-400" aria-hidden="true">/</li>
            <li class="font-medium text-slate-900">{{ __('Monitor') }}</li>
        </ol>
    </nav>

    @if (session('success'))
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">{{ session('success') }}</div>
    @endif

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <header>
                <h1 class="text-2xl font-bold tracking-tight text-brand-ink">{{ __('Monitor') }}</h1>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Create uptime monitors here; choose a label, optional path, and displayed probe region.') }}</p>
                <p class="mt-2 text-xs text-brand-moss">{{ __('Checks run from Dply infrastructure. Region labels are for your status page and future multi-region probes.') }}</p>
            </header>

            @if ($resolvedBaseUrl === null)
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                    {{ __('Add a primary domain, preview hostname, or publication URL before uptime checks can run.') }}
                </div>
            @endif

            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('New monitor') }}</h2>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Specify a path if you need a specific URL monitored inside your hostname.') }}</p>
                </div>
                <form wire:submit="addMonitor" class="p-5 space-y-4">
                    <div>
                        <label for="uptime-label" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Label') }}</label>
                        <input
                            id="uptime-label"
                            type="text"
                            wire:model="newLabel"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                            placeholder="{{ __('e.g. Homepage check') }}"
                            @disabled(! $canEdit || $resolvedBaseUrl === null)
                        />
                        @error('newLabel')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="uptime-path" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Path') }} <span class="font-normal normal-case text-brand-moss">({{ __('optional') }})</span></label>
                        <div class="mt-1 flex rounded-lg border border-brand-ink/15 bg-slate-50 shadow-sm focus-within:border-brand-sage focus-within:ring-2 focus-within:ring-brand-sage/30">
                            <span class="inline-flex items-center border-r border-brand-ink/10 bg-slate-100 px-3 text-xs font-mono text-slate-600 truncate max-w-[14rem] sm:max-w-md" title="{{ $hostnameDisplay ?? '' }}">{{ $hostnameDisplay ?? '—' }} /</span>
                            <input
                                id="uptime-path"
                                type="text"
                                wire:model="newPath"
                                class="block min-w-0 flex-1 border-0 bg-white px-3 py-2 text-sm text-brand-ink focus:ring-0"
                                placeholder="{{ __('api/health') }}"
                                @disabled(! $canEdit || $resolvedBaseUrl === null)
                            />
                        </div>
                        @error('newPath')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="uptime-region" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Location') }}</label>
                        <select
                            id="uptime-region"
                            wire:model="newProbeRegion"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                            @disabled(! $canEdit || $probeRegions === [])
                        >
                            @foreach ($probeRegions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('newProbeRegion')
                            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="{{ $btnPrimary }}" @disabled(! $canEdit || $resolvedBaseUrl === null)>
                            <span wire:loading.remove wire:target="addMonitor">{{ __('Add monitor') }}</span>
                            <span wire:loading wire:target="addMonitor">{{ __('Saving…') }}</span>
                        </button>
                    </div>
                </form>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="border-b border-brand-ink/10 px-5 py-4">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Monitors') }}</h2>
                </div>
                @if ($site->uptimeMonitors->isEmpty())
                    <p class="px-5 py-10 text-sm text-brand-moss text-center">{{ __('No monitors yet.') }}</p>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($site->uptimeMonitors as $m)
                            @php
                                $regionLabel = $probeRegions[$m->probe_region] ?? $m->probe_region;
                            @endphp
                            <li class="px-5 py-4 flex flex-wrap items-center justify-between gap-4">
                                <div class="min-w-0">
                                    <p class="font-medium text-brand-ink">{{ $m->label }}</p>
                                    <p class="mt-0.5 text-xs font-mono text-brand-moss truncate" title="{{ $hostnameDisplay }}{{ $m->normalizedPath() }}">{{ $hostnameDisplay }}{{ $m->normalizedPath() ?: '/' }}</p>
                                    <p class="mt-1 text-xs text-brand-moss">{{ $regionLabel }}</p>
                                    @if ($m->last_checked_at)
                                        <p class="mt-2 text-xs text-brand-moss">
                                            @if ($m->last_ok)
                                                <span class="text-emerald-700 font-medium">{{ __('OK') }}</span>
                                            @else
                                                <span class="text-red-700 font-medium">{{ __('Failed') }}</span>
                                            @endif
                                            @if ($m->last_http_status)
                                                · HTTP {{ $m->last_http_status }}
                                            @endif
                                            @if ($m->last_latency_ms !== null)
                                                · {{ $m->last_latency_ms }} ms
                                            @endif
                                            · {{ $m->last_checked_at->timezone(config('app.timezone'))->toDayDateTimeString() }}
                                        </p>
                                        @if ($m->last_error)
                                            <p class="mt-1 text-xs text-red-700 truncate" title="{{ $m->last_error }}">{{ $m->last_error }}</p>
                                        @endif
                                    @else
                                        <p class="mt-2 text-xs text-brand-moss">{{ __('Not checked yet.') }}</p>
                                    @endif
                                </div>
                                <div class="flex flex-wrap gap-2 shrink-0">
                                    @if ($canEdit)
                                        <button type="button" wire:click="runCheckNow('{{ $m->id }}')" wire:loading.attr="disabled" wire:target="runCheckNow" class="{{ $btnSecondary }}">
                                            <span wire:loading.remove wire:target="runCheckNow">{{ __('Check now') }}</span>
                                            <span wire:loading wire:target="runCheckNow">{{ __('Queueing…') }}</span>
                                        </button>
                                        <button type="button" wire:click="confirmRemoveMonitor('{{ $m->id }}')" class="{{ $btnSecondary }} text-red-800 border-red-200 hover:bg-red-50">
                                            {{ __('Remove') }}
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <p class="text-sm text-brand-moss">
                {{ __('Show monitors on a public') }}
                <a href="{{ route('status-pages.index') }}" class="font-medium text-brand-forest underline">{{ __('status page') }}</a>.
            </p>
        </main>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
