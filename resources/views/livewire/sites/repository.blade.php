<div class="max-w-7xl mx-auto px-4 py-8 sm:px-6 lg:px-8">
    <nav class="mb-6 text-sm text-brand-moss" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Servers') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $server->name }}">{{ $server->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[12rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="font-medium text-brand-ink">{{ __('Repository') }}</li>
        </ol>
    </nav>

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <x-page-header
                :title="__('Repository')"
                :description="__('Browse the connected repository, switch branches, and manage the source-control connection. Reads are cached for five minutes — use the per-tab refresh to bypass.')"
                doc-route="docs.index"
                flush
                compact
            />

            @if ($currentRepositoryUrl === '')
                <section class="rounded-2xl border border-amber-300 bg-amber-50 p-5">
                    <p class="text-sm font-semibold text-amber-950">{{ __('No repository connected') }}</p>
                    <p class="mt-1 text-sm text-amber-900">{{ __('Connect a GitHub, GitLab, or Bitbucket repository on the Connection tab to populate this page.') }}</p>
                </section>
            @endif

            @php
                $tabs = [
                    ['id' => 'overview',   'label' => __('Overview')],
                    ['id' => 'files',      'label' => __('Files')],
                    ['id' => 'branches',   'label' => __('Branches')],
                    ['id' => 'connection', 'label' => __('Connection')],
                ];
            @endphp

            <nav class="-mb-px flex flex-wrap gap-x-6 gap-y-1 border-b border-brand-ink/10 text-sm" aria-label="{{ __('Repository tabs') }}">
                @foreach ($tabs as $entry)
                    <button
                        type="button"
                        wire:click="$set('tab', '{{ $entry['id'] }}')"
                        @class([
                            'whitespace-nowrap border-b-2 px-1 py-2 font-medium transition-colors',
                            'border-brand-ink text-brand-ink' => $tab === $entry['id'],
                            'border-transparent text-brand-moss hover:border-brand-mist hover:text-brand-ink' => $tab !== $entry['id'],
                        ])
                    >{{ $entry['label'] }}</button>
                @endforeach
            </nav>

            <div wire:key="repository-tab-{{ $tab }}-{{ $branchInUse }}-{{ $filesPath }}">
                @includeWhen($tab === 'overview',   'livewire.sites.repository.partials.overview')
                @includeWhen($tab === 'files',      'livewire.sites.repository.partials.files')
                @includeWhen($tab === 'branches',   'livewire.sites.repository.partials.branches')
                @includeWhen($tab === 'connection', 'livewire.sites.repository.partials.connection')
            </div>
        </main>
    </div>
</div>
