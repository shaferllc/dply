<div class="mx-auto max-w-5xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Edge'), 'href' => route('edge.index'), 'icon' => 'globe-alt'],
        ['label' => __('Import'), 'icon' => 'arrow-down-tray'],
    ]" />

    <header class="mt-6">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Import from another edge host') }}</h1>
        <p class="mt-2 max-w-2xl text-sm text-brand-moss">
            {{ __('Pull a project\'s build settings, env vars, and custom domains from Vercel, Netlify, or Cloudflare Pages — we hand you off to the Edge Create form with everything pre-filled. Nothing is created on dply until you confirm.') }}
        </p>
    </header>

    <ol class="mt-6 flex flex-wrap gap-2 text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">
        @foreach (['provider' => __('Provider'), 'credential' => __('Credential'), 'projects' => __('Project'), 'preview' => __('Preview')] as $key => $label)
            <li @class([
                'rounded-full px-3 py-1',
                'bg-brand-ink text-white' => $step === $key,
                'bg-brand-sand/40 text-brand-moss' => $step !== $key,
            ])>{{ $label }}</li>
        @endforeach
    </ol>

    {{-- Step 1 — provider --}}
    @if ($step === 'provider')
        <div class="mt-6 grid gap-4 sm:grid-cols-3">
            @foreach ($providers as $provider)
                <button type="button"
                        wire:click="pickProvider('{{ $provider['key'] }}')"
                        class="group flex h-full flex-col items-start rounded-2xl border border-brand-ink/10 bg-white p-5 text-left shadow-sm transition hover:border-brand-sage hover:shadow-md dark:border-brand-mist/20 dark:bg-zinc-900">
                    <div class="text-base font-semibold text-brand-ink">{{ $provider['label'] }}</div>
                    <p class="mt-2 text-xs leading-relaxed text-brand-moss">{{ $provider['hint'] }}</p>
                    <span class="mt-4 inline-flex items-center gap-1 text-xs font-semibold text-brand-forest group-hover:underline dark:text-brand-sage">
                        {{ __('Continue') }} <span aria-hidden="true">→</span>
                    </span>
                </button>
            @endforeach
        </div>
    @endif

    {{-- Step 2 — credential --}}
    @if ($step === 'credential')
        @php
            $providerLabel = collect($providers)->firstWhere('key', $provider)['label'] ?? ucfirst($provider);
        @endphp
        <section class="mt-6 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Credential') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Authenticate with :provider', ['provider' => $providerLabel]) }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ collect($providers)->firstWhere('key', $provider)['hint'] ?? '' }}
                    </p>
                </div>
            </div>

            <div class="space-y-4 px-6 py-6 sm:px-7">
                @if ($provider === 'cloudflare_pages')
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Cloudflare account id') }}</span>
                        <input type="text" wire:model.blur="secondaryId" class="mt-1.5 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-800" placeholder="0123456789abcdef0123456789abcdef" />
                    </label>
                @elseif ($provider === 'vercel')
                    <label class="block">
                        <span class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Team id (optional)') }}</span>
                        <input type="text" wire:model.blur="secondaryId" class="mt-1.5 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-800" placeholder="team_xxx (leave blank for personal account)" />
                    </label>
                @endif

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Access token') }}</span>
                    <input type="password" wire:model.blur="apiToken" class="mt-1.5 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-800" autocomplete="off" placeholder="paste here" />
                    <p class="mt-1 text-[11px] text-brand-moss">{{ __('Token stays in-memory for this session only — dply does not persist it.') }}</p>
                </label>

                @if ($probeResult !== null && ! ($probeResult['ok'] ?? false))
                    <div class="rounded-lg border border-rose-300 bg-rose-50 px-3 py-2 text-xs text-rose-900 dark:border-rose-900/40 dark:bg-rose-950/30 dark:text-rose-200">
                        {{ $probeResult['message'] }}
                    </div>
                @endif

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="back" class="text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('← Back') }}</button>
                    <button type="button"
                            wire:click="probe"
                            wire:loading.attr="disabled"
                            wire:target="probe"
                            class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                        <span wire:loading.remove wire:target="probe">{{ __('Verify + list projects') }}</span>
                        <span wire:loading wire:target="probe">{{ __('Connecting…') }}</span>
                    </button>
                </div>
            </div>
        </section>
    @endif

    {{-- Step 3 — projects --}}
    @if ($step === 'projects')
        <section class="mt-6 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Project') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Pick a project') }}</h2>
                    @if ($probeResult && ($probeResult['principal'] ?? '') !== '')
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Authenticated as :who', ['who' => $probeResult['principal']]) }}</p>
                    @endif
                </div>
                <button type="button" wire:click="back" class="shrink-0 text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('← Change credential') }}</button>
            </div>

            @if ($loadError)
                <div class="border-b border-rose-200/60 bg-rose-50 px-6 py-3 text-xs text-rose-900 dark:border-rose-900/30 dark:bg-rose-950/30 dark:text-rose-200">{{ $loadError }}</div>
            @endif

            @if ($projects === [])
                <div class="px-6 py-10 text-center text-sm text-brand-moss">{{ __('No projects found for this credential.') }}</div>
            @else
                <ul class="divide-y divide-brand-ink/8">
                    @foreach ($projects as $project)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-medium text-brand-ink">{{ $project['name'] }}</p>
                                <p class="mt-0.5 truncate text-[11px] text-brand-moss">
                                    @if ($project['repo'])<span class="font-mono">{{ $project['repo'] }}</span>@endif
                                    @if ($project['framework'])· <span class="uppercase tracking-wide">{{ $project['framework'] }}</span>@endif
                                    @if ($project['updated_at'])· {{ \Illuminate\Support\Carbon::parse($project['updated_at'])->diffForHumans() }}@endif
                                </p>
                                @if ($project['live_url'])
                                    <a href="{{ $project['live_url'] }}" target="_blank" rel="noopener noreferrer" class="mt-0.5 inline-flex items-center gap-1 font-mono text-[11px] text-brand-forest hover:underline dark:text-brand-sage">{{ $project['live_url'] }}</a>
                                @endif
                            </div>
                            <button type="button"
                                    wire:click="previewProject('{{ $project['id'] }}')"
                                    wire:loading.attr="disabled"
                                    class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60">
                                {{ __('Preview import') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- Step 4 — preview --}}
    @if ($step === 'preview' && is_array($projectPreview))
        <section class="mt-6 overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm dark:border-brand-mist/20 dark:bg-zinc-900">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Preview') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $projectPreview['name'] }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Review the translation, then continue to the Edge Create form to deploy.') }}</p>
                </div>
                <button type="button" wire:click="back" class="shrink-0 text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('← Pick another project') }}</button>
            </div>

            <dl class="grid grid-cols-1 gap-y-3 gap-x-6 px-6 py-4 text-sm sm:grid-cols-2">
                <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Repository') }}</dt><dd class="mt-0.5 font-mono text-xs text-brand-ink break-all">{{ $projectPreview['repo'] ?? '—' }}</dd></div>
                <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Branch') }}</dt><dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $projectPreview['branch'] ?? '—' }}</dd></div>
                <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Framework') }}</dt><dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $projectPreview['framework'] ?? '—' }}</dd></div>
                <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Runtime mode') }}</dt><dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $projectPreview['runtime_mode'] }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Build command') }}</dt><dd class="mt-0.5 font-mono text-xs text-brand-ink break-all">{{ $projectPreview['build_command'] ?: __('(defaults)') }}</dd></div>
                <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Output dir') }}</dt><dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $projectPreview['output_dir'] ?: __('(framework default)') }}</dd></div>
                <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Env vars') }}</dt><dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $projectPreview['env_count'] }} {{ __('key(s)') }}</dd></div>
            </dl>

            @if ($projectPreview['env_keys'] !== [])
                <details class="border-t border-brand-ink/10 px-6 py-3 text-xs text-brand-moss">
                    <summary class="cursor-pointer font-semibold">{{ __('Env var keys (values are imported but not shown here)') }}</summary>
                    <p class="mt-2 font-mono leading-relaxed">{{ implode(', ', $projectPreview['env_keys']) }}</p>
                </details>
            @endif

            @if ($projectPreview['custom_domains'] !== [])
                @php
                    $providerLabel = match ($provider) {
                        'vercel' => 'Vercel',
                        'netlify' => 'Netlify',
                        'cloudflare_pages' => 'Cloudflare Pages',
                        default => __('your source provider'),
                    };
                    $currentTargetHint = match ($provider) {
                        'vercel' => 'cname.vercel-dns.com',
                        'netlify' => 'apex-loadbalancer.netlify.com',
                        'cloudflare_pages' => '<project>.pages.dev',
                        default => '(provider host)',
                    };
                @endphp
                <div class="border-t border-brand-ink/10 px-6 py-3 text-xs text-brand-moss">
                    <p class="font-semibold uppercase tracking-wide">{{ __('Custom domains — DNS swap runbook') }}</p>
                    <p class="mt-1 font-mono">{{ implode(', ', $projectPreview['custom_domains']) }}</p>

                    <ol class="mt-3 space-y-2 text-[11px] leading-relaxed">
                        <li>
                            <span class="font-semibold text-brand-ink">{{ __('1. Deploy first.') }}</span>
                            {{ __('Continue to Create, ship one deploy on the dply edge subdomain (we provision one for free). Validate the live site works before swapping DNS.') }}
                        </li>
                        <li>
                            <span class="font-semibold text-brand-ink">{{ __('2. Attach each domain in the Edge → Domains tab.') }}</span>
                            {{ __('dply will show you a per-domain target hostname like') }} <code class="font-mono text-brand-ink">your-site.dply.app</code>. {{ __('Note it down.') }}
                        </li>
                        <li>
                            <span class="font-semibold text-brand-ink">{{ __('3. Lower TTL at your DNS provider.') }}</span>
                            {{ __('Drop existing CNAME TTLs to 60s and wait one full TTL so the swap propagates quickly.') }}
                        </li>
                        <li>
                            <span class="font-semibold text-brand-ink">{{ __('4. Update the CNAME.') }}</span>
                            {{ __('Change the record currently pointing at :from to point at :to.', ['from' => $currentTargetHint, 'to' => '(target shown in dply Domains tab)']) }}
                        </li>
                        <li>
                            <span class="font-semibold text-brand-ink">{{ __('5. Verify and decommission.') }}</span>
                            {{ __('Hit each domain through dply (Domains tab shows when the certificate is live), then remove the old custom-domain entry on :provider so it stops billing or holding the hostname.', ['provider' => $providerLabel]) }}
                        </li>
                    </ol>
                    <p class="mt-2 text-[10px] text-brand-mist">{{ __('Apex (root) domains: most providers require ALIAS/ANAME or a flattening CNAME — dply provides the same target host you point a CNAME at.') }}</p>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-4">
                <p class="text-xs text-brand-moss">{{ __('Nothing has been created yet. Continue to confirm + deploy.') }}</p>
                <button type="button"
                        wire:click="handOffToCreate"
                        class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90">
                    {{ __('Continue to Create →') }}
                </button>
            </div>
        </section>
    @endif
</div>
