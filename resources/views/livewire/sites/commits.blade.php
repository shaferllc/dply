@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
@endphp

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
            <li class="font-medium text-brand-ink">{{ __('Commits') }}</li>
        </ol>
    </nav>

    <div class="space-y-6 lg:grid lg:grid-cols-12 lg:gap-10 lg:space-y-0">
        @include('livewire.sites.settings.partials.sidebar')

        <main class="min-w-0 space-y-6 lg:col-span-9">
            <header class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-brand-ink">{{ __('Commits') }}</h1>
                    <p class="mt-1 text-sm text-brand-moss max-w-2xl">
                        {{ __('Recent commits from your connected Git provider for this site’s repository and branch. Links open on GitHub, GitLab, or Bitbucket.') }}
                    </p>
                </div>
            </header>

            <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-0">
                        @if ($remoteLabel)
                            <p class="text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Repository') }}</p>
                            <p class="mt-1 font-mono text-sm text-brand-ink truncate" title="{{ $remoteLabel }}">{{ $remoteLabel }}</p>
                            @if ($provider)
                                <span class="mt-1 inline-flex rounded-md bg-white/80 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ $provider }}</span>
                            @endif
                        @else
                            <p class="text-sm text-brand-moss">{{ __('No repository URL on this site yet.') }}</p>
                        @endif
                    </div>
                    <a
                        href="{{ route('sites.show', [$server, $site, 'section' => 'deploy']) }}"
                        wire:navigate
                        class="shrink-0 text-xs font-medium text-brand-sage hover:text-brand-forest"
                    >
                        {{ __('Edit in Deploy settings') }}
                    </a>
                </div>

                <div class="p-5 space-y-4 border-b border-brand-ink/10 bg-white">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end">
                        <div class="flex-1 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label for="git-branch" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Branch') }}</label>
                                <input
                                    id="git-branch"
                                    type="text"
                                    wire:model.live.debounce.400ms="branch"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                                    placeholder="main"
                                    autocomplete="off"
                                />
                            </div>
                            <div>
                                <label for="commit-filter" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Filter') }}</label>
                                <input
                                    id="commit-filter"
                                    type="search"
                                    wire:model.live.debounce.300ms="filter"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                                    placeholder="{{ __('Message, author, or SHA…') }}"
                                    autocomplete="off"
                                />
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-2 shrink-0">
                            <button type="button" wire:click="refreshCommits" wire:loading.attr="disabled" class="{{ $btnPrimary }}">
                                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" wire:target="refreshCommits" />
                                <span wire:loading.remove wire:target="refreshCommits">{{ __('Refresh') }}</span>
                                <span wire:loading wire:target="refreshCommits">{{ __('Loading…') }}</span>
                            </button>
                        </div>
                    </div>
                    @if ($lastDeployedSha)
                        <p class="text-xs text-brand-moss">
                            {{ __('Latest successful deploy:') }}
                            <span class="font-mono text-brand-ink">{{ \Illuminate\Support\Str::limit($lastDeployedSha, 12, '') }}</span>
                            — {{ __('matches are highlighted below when the SHA appears in this list.') }}
                        </p>
                    @endif
                </div>

                @if ($fetchError)
                    <div class="px-5 py-6">
                        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                            {{ $fetchError }}
                        </div>
                        <p class="mt-4 text-sm text-brand-moss">
                            <a href="{{ route('profile.source-control') }}" wire:navigate class="font-medium text-brand-sage hover:text-brand-forest underline">{{ __('Source control connections') }}</a>
                        </p>
                    </div>
                @elseif ($filteredCommits === [])
                    <div class="px-5 py-12 text-center text-sm text-brand-moss">
                        @if ($filter !== '')
                            {{ __('No commits match your filter.') }}
                        @else
                            {{ __('No commits yet, or the branch has no history.') }}
                        @endif
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10" role="list">
                        @foreach ($filteredCommits as $c)
                            @php
                                $shaMatch = $lastDeployedSha !== null
                                    && strcasecmp(substr((string) $lastDeployedSha, 0, 7), substr($c['sha'], 0, 7)) === 0;
                                $when = $this->relativeTime($c['committed_at'] ?? null);
                            @endphp
                            <li class="flex flex-wrap items-start justify-between gap-4 px-5 py-4 hover:bg-brand-sand/20 transition-colors">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono text-xs font-semibold text-brand-sage bg-brand-sand/60 px-1.5 py-0.5 rounded">{{ $c['short_sha'] }}</span>
                                        @if ($shaMatch)
                                            <span class="inline-flex items-center rounded-full bg-green-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-green-800 ring-1 ring-green-200">{{ __('Deployed') }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-2 font-medium text-brand-ink text-sm leading-snug break-words">{{ $c['message'] }}</p>
                                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                                        <span class="inline-flex items-center gap-1.5">
                                            <span class="inline-flex h-6 w-6 items-center justify-center rounded-full bg-brand-ink/10 text-[10px] font-bold text-brand-ink" aria-hidden="true">
                                                {{ strtoupper(mb_substr($c['author_name'], 0, 1)) }}
                                            </span>
                                            {{ $c['author_name'] }}
                                        </span>
                                        @if ($when)
                                            <span class="text-brand-mist">{{ $when }}</span>
                                        @endif
                                    </div>
                                </div>
                                <div class="shrink-0 flex items-center gap-2">
                                    @if (! empty($c['html_url']))
                                        <a
                                            href="{{ $c['html_url'] }}"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="{{ $btnSecondary }} !py-2 !px-3 !text-[11px]"
                                        >
                                            {{ __('View commit') }}
                                        </a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </main>
    </div>
</div>
