<section class="space-y-6">
    {{-- Nothing to show until a repository is connected; the page-level
         "No repository connected" card already explains the next steps. --}}
    @if ($currentRepositoryUrl !== '')
    <div class="dply-card overflow-hidden">
        <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-code-bracket-square class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $providerKind !== '' ? ucfirst($providerKind) : __('Repository') }}</p>
                    <h2 class="mt-0.5 truncate text-base font-semibold text-brand-ink">
                        {{ $overviewCommits['remote_label'] ?? ($currentRepositoryUrl ?: __('No repository connected')) }}
                    </h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Deploy branch:') }}
                        <code class="font-mono text-brand-ink">{{ $currentBranch }}</code>
                        @if ($branchInUse !== $currentBranch)
                            <span class="ml-2 inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-amber-900">{{ __('viewing :ref', ['ref' => $branchInUse]) }}</span>
                        @endif
                    </p>
                </div>
            </div>
            @if ($currentRepositoryUrl !== '')
                <a href="{{ str_starts_with($currentRepositoryUrl, 'http') ? $currentRepositoryUrl : '#' }}"
                   target="_blank" rel="noopener noreferrer"
                   class="shrink-0 inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                    <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                    {{ __('Open on provider') }}
                </a>
            @endif
        </div>
    </div>

    <div class="dply-card overflow-hidden">
        <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('History') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recent commits') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Latest five commits on the viewed branch. Open the Commits tab for the full history.') }}</p>
                </div>
            </div>
            <button type="button" wire:click="$set('tab', 'commits')"
               class="shrink-0 text-sm font-semibold text-brand-forest hover:text-brand-sage hover:underline">{{ __('See all →') }}</button>
        </div>

        <div class="px-6 py-6 sm:px-7">
            @if (! empty($overviewCommits['notice']))
                {{-- Non-fatal: the configured branch was missing, so we fell back to
                     the repo's default branch. Tell the operator they can pick another. --}}
                <div class="mb-4 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                    <x-heroicon-o-information-circle class="mt-0.5 h-4 w-4 shrink-0 text-amber-500" aria-hidden="true" />
                    <span class="min-w-0">{{ $overviewCommits['notice'] }}</span>
                </div>
            @endif
            @if (! ($overviewCommits['ok'] ?? false))
                <div class="flex flex-col gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <span class="min-w-0">{{ $overviewCommits['error'] ?? __('Could not load commits.') }}</span>
                    <button type="button" wire:click="reloadRepository" wire:loading.attr="disabled" wire:target="reloadRepository"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-rose-300 bg-white/70 px-2.5 py-1 font-medium text-rose-900 hover:bg-white disabled:opacity-60">
                        <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="reloadRepository" />
                        <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" wire:loading wire:target="reloadRepository" />
                        {{ __('Retry') }}
                    </button>
                </div>
            @elseif (empty($overviewCommits['commits']))
                <div class="rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 p-6 text-center text-sm text-brand-moss">
                    {{ __('No commits on this branch yet.') }}
                </div>
            @else
                <ul role="list" class="divide-y divide-brand-ink/10">
                    @foreach ($overviewCommits['commits'] as $commit)
                        <li class="grid grid-cols-[1fr_auto] gap-4 py-3 first:pt-0 last:pb-0" wire:key="overview-commit-{{ $commit['sha'] }}">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <code class="rounded bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[11px] font-semibold text-brand-ink">{{ $commit['short_sha'] }}</code>
                                </div>
                                <p class="mt-1 truncate text-sm font-medium text-brand-ink">{{ $commit['message'] }}</p>
                                <p class="mt-0.5 truncate text-xs text-brand-moss">
                                    {{ $commit['author_name'] }}
                                    @if (! empty($commit['committed_at']))
                                        · {{ \Illuminate\Support\Carbon::parse($commit['committed_at'])->diffForHumans() }}
                                    @endif
                                </p>
                            </div>
                            @if (! empty($commit['html_url']))
                                <a href="{{ $commit['html_url'] }}" target="_blank" rel="noopener noreferrer"
                                   class="shrink-0 self-center inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if (! empty($overviewCommits['account']['label']))
                {{-- Which linked identity answered this read — so a wrong-token
                     404 is self-evident instead of looking like a missing repo. --}}
                <p class="mt-4 flex flex-wrap items-center gap-1.5 border-t border-brand-ink/10 pt-3 text-[11px] text-brand-moss">
                    <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                    <span>{{ __('Read using :label', ['label' => $overviewCommits['account']['label']]) }}</span>
                    @if (! empty($overviewCommits['account']['kind']))
                        <span class="rounded bg-brand-sand/50 px-1.5 py-0.5 font-medium uppercase tracking-wide">{{ $overviewCommits['account']['kind'] }}</span>
                    @endif
                    <a href="{{ route('sites.repository', [$server, $site, 'repo_tab' => 'connection']) }}" wire:navigate
                       class="font-semibold text-brand-forest hover:underline">{{ __('Change account') }}</a>
                </p>
            @endif
        </div>
    </div>

    <div class="dply-card overflow-hidden">
        <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <x-icon-badge>
                    <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Docs') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('README') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Rendered from the branch root. Markdown only — other formats display as plain text.') }}</p>
                </div>
            </div>
            @if (! empty($overviewReadme['name']))
                <span class="shrink-0 font-mono text-xs text-brand-mist">{{ $overviewReadme['name'] }}</span>
            @endif
        </div>

        <div class="px-6 py-6 sm:px-7">
            @if ($overviewReadme === null)
                <div class="text-sm text-brand-moss">{{ __('Sign in to load the README.') }}</div>
            @elseif (! ($overviewReadme['ok'] ?? false))
                <div class="flex flex-col gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-900 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <span class="min-w-0">{{ $overviewReadme['error'] ?? __('Could not load README.') }}</span>
                    <button type="button" wire:click="reloadRepository" wire:loading.attr="disabled" wire:target="reloadRepository"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-md border border-rose-300 bg-white/70 px-2.5 py-1 font-medium text-rose-900 hover:bg-white disabled:opacity-60">
                        <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="reloadRepository" />
                        <x-heroicon-o-arrow-path class="h-4 w-4 animate-spin" wire:loading wire:target="reloadRepository" />
                        {{ __('Retry') }}
                    </button>
                </div>
            @elseif (($overviewReadme['content_html'] ?? '') === '')
                <div class="rounded-lg border border-dashed border-brand-ink/15 bg-brand-sand/20 p-6 text-center text-sm text-brand-moss">
                    {{ __('No README found at the branch root.') }}
                </div>
            @else
                <div class="prose prose-sm max-w-none dark:prose-invert">{!! $overviewReadme['content_html'] !!}</div>
            @endif
        </div>
    </div>
    @endif
</section>
