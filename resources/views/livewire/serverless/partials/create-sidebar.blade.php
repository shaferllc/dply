{{-- Serverless create flow — sticky companion summary (sibling of profile-shell form). --}}
@php
    $appLabel = trim((string) $name) !== '' ? $name : __('Untitled app');
    $repoLabel = trim((string) $git_repository_url) !== '' ? $git_repository_url : __('Repository (unset)');
    $branchLabel = trim((string) $git_branch) !== '' ? $git_branch : 'main';
    $regionLabel = $regions[$region] ?? $region;
    $runtimeLabel = $runtimes[$runtime] ?? $runtime;
    $deliveryLabel = $delivery_mode === 'managed'
        ? __('Dply-hosted')
        : __('Your account (BYO)');
@endphp
<aside class="space-y-4 lg:sticky lg:top-24 lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto lg:overscroll-contain lg:self-start">
    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
        <div class="border-b border-brand-ink/8 bg-gradient-to-br from-brand-sage/10 via-transparent to-brand-gold/10 px-5 py-3.5 dark:border-brand-mist/15">
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Deploy summary') }}</p>
            <p class="mt-1 truncate text-sm font-semibold text-brand-ink">{{ $appLabel }}</p>
        </div>
        <dl class="divide-y divide-brand-ink/8 text-sm dark:divide-brand-mist/15">
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Source') }}</dt>
                <dd class="min-w-0 text-end font-mono text-xs text-brand-ink dark:text-brand-cream">
                    <span class="block truncate">{{ $repoLabel }}</span>
                    <span class="mt-0.5 block text-[11px] text-brand-moss">
                        {{ match ($git_ref_kind ?? 'branch') {
                            'tag' => __('Tag'),
                            'commit' => __('Commit'),
                            default => __('Branch'),
                        } }}
                        {{ $branchLabel }}
                    </span>
                </dd>
            </div>
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Runtime') }}</dt>
                <dd class="min-w-0 text-end text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ $runtimeLabel !== '' ? $runtimeLabel : __('—') }}</dd>
            </div>
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Region') }}</dt>
                <dd class="min-w-0 text-end text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ $regionLabel !== '' ? $regionLabel : __('—') }}</dd>
            </div>
            <div class="flex items-start justify-between gap-3 px-5 py-2.5">
                <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Hosting') }}</dt>
                <dd class="min-w-0 text-end text-xs font-semibold text-brand-ink dark:text-brand-cream">{{ $deliveryLabel }}</dd>
            </div>
        </dl>
    </div>

    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
        <div class="border-b border-brand-ink/8 bg-gradient-to-br from-brand-sage/10 via-transparent to-brand-gold/10 px-5 py-3.5 dark:border-brand-mist/15">
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-sage">{{ __('Serverless') }}</p>
            <p class="mt-1 text-sm font-semibold text-brand-ink">{{ __('From repo to live app') }}</p>
        </div>
        <ol class="space-y-0 px-5 py-3.5">
            @foreach ([
                ['icon' => 'code-bracket', 'title' => __('Point at a repo'), 'desc' => __('GitHub repo with your Laravel (or PHP) app.')],
                ['icon' => 'bolt', 'title' => __('Pick a runtime'), 'desc' => __('Auto-detect or pin PHP, Node, Python, Go.')],
                ['icon' => 'cloud-arrow-up', 'title' => __('Deploy the site'), 'desc' => __('Public HTTPS URL — no machine to manage.')],
            ] as $step)
                <li class="relative flex gap-3 pb-3.5 last:pb-0">
                    @if (! $loop->last)
                        <span class="absolute start-[1.125rem] top-9 h-[calc(100%-1rem)] w-px bg-brand-ink/10 dark:bg-brand-mist/20" aria-hidden="true"></span>
                    @endif
                    <span class="relative z-10 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/20 dark:bg-brand-sage/20 dark:text-brand-sage dark:ring-brand-sage/30">
                        @switch($step['icon'])
                            @case('code-bracket')
                                <x-heroicon-o-code-bracket class="h-4 w-4" aria-hidden="true" />
                                @break
                            @case('bolt')
                                <x-heroicon-o-bolt class="h-4 w-4" aria-hidden="true" />
                                @break
                            @default
                                <x-heroicon-o-cloud-arrow-up class="h-4 w-4" aria-hidden="true" />
                        @endswitch
                    </span>
                    <div class="min-w-0 pt-0.5">
                        <p class="text-sm font-semibold text-brand-ink">{{ $step['title'] }}</p>
                        <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ $step['desc'] }}</p>
                    </div>
                </li>
            @endforeach
        </ol>
    </div>

    <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Works great with') }}</p>
        <div class="mt-2.5 flex flex-wrap gap-1.5">
            @foreach (['Laravel', 'PHP', 'Node.js', 'Python', 'Go'] as $framework)
                <span class="inline-flex items-center rounded-lg border border-brand-ink/10 bg-brand-cream/60 px-2 py-0.5 text-[11px] font-semibold text-brand-forest dark:border-brand-mist/25 dark:bg-zinc-800 dark:text-brand-sage">
                    {{ $framework }}
                </span>
            @endforeach
        </div>
        <p class="mt-2.5 text-xs leading-relaxed text-brand-moss">{{ __('Full web apps on a serverless runtime. Prefer always-on containers? Use Cloud. Static/SSG? Use Edge.') }}</p>
    </div>

    <div class="rounded-2xl border border-brand-sage/25 bg-gradient-to-br from-brand-cream via-white to-brand-sand/30 p-4 shadow-sm dark:border-brand-sage/20 dark:from-zinc-900 dark:via-zinc-900 dark:to-brand-sand/10">
        <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Estimated cost') }}</p>
        <p class="mt-1.5 text-3xl font-semibold tracking-tight text-brand-ink">
            @if ($delivery_mode === 'managed')
                {{ __('from') }} ${{ number_format($functionFee, 2) }}<span class="text-base font-medium text-brand-moss">/mo</span>
            @else
                ${{ number_format($functionFee, 2) }}<span class="text-base font-medium text-brand-moss">/mo</span>
            @endif
        </p>
        <p class="mt-1.5 text-xs leading-relaxed text-brand-moss">
            @if ($delivery_mode === 'managed')
                {{ __('Flat dply per-app fee plus metered usage above a monthly allowance.') }}
            @else
                {{ __('Flat dply per-app fee once live. Provider usage bills to your account.') }}
            @endif
        </p>
        <p class="mt-1 text-[11px] leading-relaxed text-brand-mist">{{ __('Databases or Redis you attach later are billed separately.') }}</p>
    </div>
</aside>
