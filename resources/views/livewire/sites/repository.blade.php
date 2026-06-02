@php
    // When embedded inside another page (e.g. the Deployments page's Settings /
    // Commits tabs), suppress the breadcrumb + sidebar + page header chrome so
    // the host page provides the framing. When $lockedTab is also set, suppress
    // the internal tablist too and render only the matching tab partial.
    $isEmbedded = $embedded ?? false;
    $isLocked = ($lockedTab ?? '') !== '';
@endphp

@if (! $isEmbedded)
<div class="max-w-7xl mx-auto px-4 pt-8 pb-16 sm:px-6 lg:px-8">
    @include('livewire.sites.partials.workspace-breadcrumb-bar', [
        'server' => $server,
        'site' => $site,
        'currentLabel' => __('Repository'),
        'currentIcon' => 'folder-open',
    ])

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :title="__('Repository')"
                :description="__('Browse the connected repository, switch branches, and manage the source-control connection. Reads are cached for five minutes — use the per-tab refresh to bypass.')"
                :show-documentation="false"
                flush
                compact
            />
@else
<div class="space-y-6">
@endif

            @if ($currentRepositoryUrl === '')
                @if (! $isEmbedded)
                <section class="dply-card overflow-hidden border-amber-200">
                    <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                                <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Warning') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('No repository connected') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                    @if ($site->canRechooseApp())
                                        {{ __('This site doesn’t have an application yet. Install one (WordPress, Laravel, Statamic…) or deploy an existing repository.') }}
                                    @else
                                        {{ __('Connect a GitHub, GitLab, or Bitbucket repository below to browse commits, files, and branches.') }}
                                    @endif
                                </p>
                                @if ($site->canRechooseApp())
                                    <div class="mt-3">
                                        <a href="{{ route('sites.choose-app', [$server, $site]) }}" wire:navigate
                                            class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md shadow-brand-ink/15 transition-colors hover:bg-brand-forest">
                                            <x-heroicon-o-squares-2x2 class="h-4 w-4" aria-hidden="true" />
                                            {{ __('Choose an application') }}
                                        </a>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </section>
                @endif

                @include('livewire.sites.repository.partials.connection')
            @else
                @php
                    $tabs = [
                        ['id' => 'overview',   'label' => __('Overview'),   'icon' => 'heroicon-o-home'],
                        ['id' => 'commits',    'label' => __('Commits'),    'icon' => 'heroicon-o-clock'],
                        ['id' => 'files',      'label' => __('Files'),      'icon' => 'heroicon-o-folder'],
                        ['id' => 'branches',   'label' => __('Branches'),   'icon' => 'heroicon-o-rectangle-stack'],
                        ['id' => 'connection', 'label' => __('Connection'), 'icon' => 'heroicon-o-link'],
                    ];
                @endphp

                @unless ($isLocked)
                    <x-server-workspace-tablist :aria-label="__('Repository sections')">
                        @foreach ($tabs as $entry)
                            <x-server-workspace-tab
                                id="repository-tab-{{ $entry['id'] }}"
                                :active="$tab === $entry['id']"
                                :icon="$entry['icon']"
                                wire:click="$set('tab', '{{ $entry['id'] }}')"
                            >{{ $entry['label'] }}</x-server-workspace-tab>
                        @endforeach
                    </x-server-workspace-tablist>
                @endunless

                <div wire:key="repository-tab-{{ $tab }}-{{ $branchInUse }}-{{ $filesPath }}">
                    @includeWhen($tab === 'overview',   'livewire.sites.repository.partials.overview')
                    @includeWhen($tab === 'commits',    'livewire.sites.repository.partials.commits')
                    @includeWhen($tab === 'files',      'livewire.sites.repository.partials.files')
                    @includeWhen($tab === 'branches',   'livewire.sites.repository.partials.branches')
                    @includeWhen($tab === 'connection', 'livewire.sites.repository.partials.connection')
                </div>
            @endif

@if (! $isEmbedded)
        </main>
    </div>
</div>
@else
</div>
@endif
