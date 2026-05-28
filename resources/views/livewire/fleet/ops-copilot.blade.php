<div class="mx-auto max-w-7xl px-6 py-10">
    @include('livewire.fleet._tabs')

    <header class="mb-6 border-b border-brand-ink/10 pb-4">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Ops Copilot') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-brand-moss">
            {{ __('Cross-engine deploy triage for AI-built repos — reads the latest failure log, repo config, and fleet intelligence, then suggests concrete fixes. Heuristic v1; optional LLM synthesis when configured.') }}
        </p>
    </header>

    <div class="grid gap-6 lg:grid-cols-[minmax(0,280px)_1fr]">
        <aside class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm ring-1 ring-brand-ink/[0.04]">
            <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Recent failures') }}</h2>
            @if ($candidates->isEmpty())
                <p class="mt-3 text-sm text-brand-moss">{{ __('No failed deploys in this org. Copilot activates when a BYO or Edge build fails.') }}</p>
            @else
                <ul class="mt-3 space-y-1">
                    @foreach ($candidates as $row)
                        <li>
                            <button
                                type="button"
                                wire:click="$set('siteId', '{{ $row['id'] }}')"
                                @class([
                                    'w-full rounded-xl border px-3 py-2.5 text-left text-sm transition',
                                    'border-brand-forest bg-brand-sand/50 text-brand-ink' => $siteId === $row['id'],
                                    'border-transparent text-brand-moss hover:border-brand-ink/10 hover:bg-brand-sand/30 hover:text-brand-ink' => $siteId !== $row['id'],
                                ])
                            >
                                <span class="font-semibold text-brand-ink">{{ $row['name'] }}</span>
                                <span class="mt-0.5 block text-xs uppercase tracking-wide text-brand-moss">{{ $row['product'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </aside>

        <section class="min-w-0 space-y-6">
            @if ($siteId === '' || $selectedSite === null)
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/20 p-8 text-center text-sm text-brand-moss">
                    <p class="font-medium text-brand-ink">{{ __('Pick a site with a failed deploy') }}</p>
                    <p class="mt-1">{{ __('Suggestions combine deploy logs, dply.yaml snapshots, intelligence alerts, and server saved commands.') }}</p>
                </div>
            @elseif ($context === null)
                <div class="rounded-xl border border-amber-200 bg-amber-50/60 p-6 text-sm text-amber-950">
                    <p class="font-medium">{{ __('No failure context for this site.') }}</p>
                    <p class="mt-1">{{ __('The latest settled deploy may have succeeded since this list was built. Refresh or pick another site.') }}</p>
                </div>
            @else
                @php
                    $siteRow = $context['site'];
                    $failure = $context['failure'];
                    $workspaceUrl = $siteRow['server_id']
                        ? route('sites.show', ['server' => $siteRow['server_id'], 'site' => $siteRow['id']]).'?section=deploy'
                        : null;
                @endphp

                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ $siteRow['name'] }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ strtoupper($siteRow['product']) }}
                            @if ($siteRow['runtime'])
                                · {{ $siteRow['runtime'] }}
                            @endif
                            @if ($failure['failed_at'] ?? null)
                                · {{ __('Failed') }} {{ \Illuminate\Support\Carbon::parse($failure['failed_at'])->diffForHumans() }}
                            @endif
                        </p>
                    </div>
                    @if ($workspaceUrl)
                        <a href="{{ $workspaceUrl }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            {{ __('Open deploy settings') }}
                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                        </a>
                    @endif
                </div>

                @if (count($context['suggestions']) > 0)
                    <div class="space-y-3">
                        <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Suggested fixes') }}</h3>
                        @foreach ($context['suggestions'] as $suggestion)
                            <article class="rounded-2xl border border-brand-sage/30 bg-brand-sage/5 p-5 shadow-sm">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="text-sm font-semibold text-brand-ink">{{ $suggestion['title'] }}</h4>
                                    <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                        {{ $suggestion['confidence'] }}
                                    </span>
                                </div>
                                <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ $suggestion['summary'] }}</p>
                                @if (! empty($suggestion['doc_slug']))
                                    <p class="mt-3">
                                        <x-docs-link :slug="$suggestion['doc_slug']" class="text-xs font-semibold text-brand-sage hover:text-brand-forest">
                                            {{ __('Read docs') }}
                                        </x-docs-link>
                                    </p>
                                @endif
                            </article>
                        @endforeach
                    </div>
                @endif

                @if (count($context['intelligence_alerts']) > 0)
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Related intelligence') }}</h3>
                        <ul class="mt-3 space-y-2">
                            @foreach ($context['intelligence_alerts'] as $alert)
                                <li class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 text-sm">
                                    <span class="font-semibold text-brand-ink">{{ $alert['title'] }}</span>
                                    @if ($alert['summary'] !== '')
                                        <p class="mt-1 text-brand-moss">{{ $alert['summary'] }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                        <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Deploy settings') }}</h3>
                        <dl class="mt-3 space-y-2 text-sm">
                            @foreach ($context['deploy_settings'] as $key => $value)
                                @if (is_string($value) && $value !== '')
                                    <div>
                                        <dt class="text-xs font-semibold text-brand-moss">{{ str_replace('_', ' ', $key) }}</dt>
                                        <dd class="mt-0.5 font-mono text-xs text-brand-ink break-all">{{ $value }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    </div>

                    @if (is_array($context['repo_config']) && $context['repo_config'] !== [])
                        <div class="rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                            <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Repo config snapshot') }}</h3>
                            <pre class="mt-3 max-h-48 overflow-auto rounded-lg bg-brand-sand/40 p-3 font-mono text-[11px] leading-5 text-brand-ink">{{ json_encode($context['repo_config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                        </div>
                    @endif
                </div>

                @if (count($context['saved_commands']) > 0)
                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Server saved commands') }}</h3>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Runbook commands on the host — useful after applying a fix.') }}</p>
                        <ul class="mt-2 flex flex-wrap gap-2">
                            @foreach ($context['saved_commands'] as $commandName)
                                <li class="rounded-full bg-brand-sand/50 px-3 py-1 text-xs font-medium text-brand-ink ring-1 ring-brand-ink/10">{{ $commandName }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (($failure['summary'] ?? '') !== '' || ($failure['log_excerpt'] ?? '') !== '')
                    <details class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
                        <summary class="cursor-pointer px-5 py-4 text-sm font-semibold text-brand-ink">{{ __('Failure log excerpt') }}</summary>
                        <div class="border-t border-brand-ink/10 px-5 py-4">
                            @if (($failure['summary'] ?? '') !== '')
                                <p class="mb-3 font-mono text-xs text-rose-900">{{ $failure['summary'] }}</p>
                            @endif
                            @if (($failure['log_excerpt'] ?? '') !== '')
                                <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words font-mono text-[11px] leading-5 text-brand-ink">{{ $failure['log_excerpt'] }}</pre>
                            @endif
                        </div>
                    </details>
                @endif

                @if ($context['llm_enabled'])
                    <p class="text-xs text-brand-moss">{{ __('LLM synthesis is enabled for this environment — heuristic suggestions may be augmented in a future release.') }}</p>
                @endif
            @endif
        </section>
    </div>
</div>
