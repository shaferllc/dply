<div>
    <x-fleet-shell
        :title="__('Ops Copilot')"
        :description="__('Cross-engine deploy triage for AI-built repos — reads the latest failure log, repo config, and fleet intelligence, then suggests concrete fixes. Heuristic v1; optional LLM synthesis when configured.')"
        :section="__('Copilot')"
        icon="heroicon-o-sparkles"
    >
        <div class="grid lg:grid-cols-[minmax(0,280px)_1fr] lg:divide-x lg:divide-brand-ink/10">
            <aside class="min-w-0 border-b border-brand-ink/10 lg:border-b-0">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3 sm:px-4">
                    <h2 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Recent failures') }}</h2>
                </div>
                <div class="px-4 py-4 sm:px-5">
                    @if ($candidates->isEmpty())
                        <p class="text-sm text-brand-moss">{{ __('No failed deploys in this org. Copilot activates when a BYO or Edge build fails.') }}</p>
                    @else
                        <ul class="space-y-1">
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
                </div>
            </aside>

            <section class="min-w-0 divide-y divide-brand-ink/10">
                @if ($siteId === '' || $selectedSite === null)
                    <x-fleet-empty :title="__('Pick a site with a failed deploy')">
                        <p class="mt-1">{{ __('Suggestions combine deploy logs, dply.yaml snapshots, intelligence alerts, and server saved commands.') }}</p>
                    </x-fleet-empty>
                @elseif ($context === null)
                    <div class="bg-amber-50/60 px-5 py-6 text-sm text-amber-950 sm:px-6">
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

                    <div class="flex flex-wrap items-start justify-between gap-3 px-5 py-5 sm:px-6">
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
                        <div class="px-5 py-5 sm:px-6">
                            <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Suggested fixes') }}</h3>
                            <div class="mt-3 space-y-3">
                                @foreach ($context['suggestions'] as $suggestion)
                                    <article class="rounded-xl border border-brand-sage/30 bg-brand-sage/5 p-4">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="text-sm font-semibold text-brand-ink">{{ $suggestion['title'] }}</h4>
                                            <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                                {{ $suggestion['confidence'] }}
                                            </span>
                                            @if (($suggestion['source'] ?? 'heuristic') === 'llm')
                                                <span class="rounded-full bg-brand-forest/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-forest/20">
                                                    {{ __('AI') }}
                                                </span>
                                            @endif
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
                        </div>
                    @endif

                    @if ($llmCanRun ?? false)
                        <div
                            class="px-5 py-5 sm:px-6"
                            @if ($llmRun?->isPending()) wire:poll.3s="refreshLlmRun" @endif
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('AI analysis') }}</h3>
                                    <p class="mt-1 text-sm text-brand-moss">{{ __('Optional LLM synthesis reads the same deploy context and suggests fixes when heuristics miss.') }}</p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="generateLlmAnalysis"
                                    wire:loading.attr="disabled"
                                    wire:target="generateLlmAnalysis"
                                    @disabled($llmRun?->isPending())
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-brand-forest/30 bg-white px-3 py-2 text-xs font-semibold text-brand-forest shadow-sm hover:bg-brand-sage/10 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    <span wire:loading.remove wire:target="generateLlmAnalysis">{{ __('Generate analysis') }}</span>
                                    <span wire:loading wire:target="generateLlmAnalysis">{{ __('Analyzing…') }}</span>
                                </button>
                            </div>

                            @if ($llmRun?->isPending())
                                <p class="mt-4 text-sm text-brand-moss">{{ __('AI analysis is running…') }}</p>
                            @elseif ($llmRun?->status === 'failed')
                                <p class="mt-4 text-sm text-rose-800">{{ $llmRun->error_message ?? __('AI analysis failed.') }}</p>
                            @elseif ($llmNarrative)
                                <p class="mt-4 text-sm leading-relaxed text-brand-ink">{{ $llmNarrative }}</p>
                            @endif

                            @if (count($llmSuggestions ?? []) > 0)
                                <div class="mt-4 space-y-3">
                                    @foreach ($llmSuggestions as $suggestion)
                                        <article class="rounded-xl border border-brand-forest/15 bg-brand-cream/30 p-4">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h4 class="text-sm font-semibold text-brand-ink">{{ $suggestion['title'] }}</h4>
                                                <span class="rounded-full bg-brand-forest/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Suggested by AI') }}</span>
                                            </div>
                                            <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ $suggestion['summary'] }}</p>
                                            @if (! empty($suggestion['actions']))
                                                <div class="mt-3 flex flex-wrap gap-2">
                                                    @foreach ($suggestion['actions'] as $action)
                                                        <a href="{{ $action['url'] }}" wire:navigate class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-brand-sand/40 px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/70">
                                                            {{ $action['label'] }}
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @endif
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
                        </div>
                    @endif

                    @if (count($context['intelligence_alerts']) > 0)
                        <div class="px-5 py-5 sm:px-6">
                            <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Related intelligence') }}</h3>
                            <ul class="mt-3 divide-y divide-brand-ink/10 border-y border-brand-ink/10">
                                @foreach ($context['intelligence_alerts'] as $alert)
                                    <li class="py-3 text-sm">
                                        <span class="font-semibold text-brand-ink">{{ $alert['title'] }}</span>
                                        @if ($alert['summary'] !== '')
                                            <p class="mt-1 text-brand-moss">{{ $alert['summary'] }}</p>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="grid gap-0 md:grid-cols-2 md:divide-x md:divide-brand-ink/10">
                        <div class="px-5 py-5 sm:px-6">
                            <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Deploy settings') }}</h3>
                            <dl class="mt-3 space-y-2 text-sm">
                                @foreach ($context['deploy_settings'] as $key => $value)
                                    @if (is_string($value) && $value !== '')
                                        <div>
                                            <dt class="text-xs font-semibold text-brand-moss">{{ str_replace('_', ' ', $key) }}</dt>
                                            <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $value }}</dd>
                                        </div>
                                    @endif
                                @endforeach
                            </dl>
                        </div>

                        @if (is_array($context['repo_config']) && $context['repo_config'] !== [])
                            <div class="border-t border-brand-ink/10 px-5 py-5 md:border-t-0 sm:px-6">
                                <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Repo config snapshot') }}</h3>
                                <pre class="mt-3 max-h-48 overflow-auto rounded-lg bg-brand-sand/40 p-3 font-mono text-[11px] leading-5 text-brand-ink">{{ json_encode($context['repo_config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                            </div>
                        @endif
                    </div>

                    @if (count($context['saved_commands']) > 0)
                        <div class="px-5 py-5 sm:px-6">
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
                        <details>
                            <summary class="cursor-pointer bg-brand-sand/20 px-5 py-4 text-sm font-semibold text-brand-ink sm:px-6">{{ __('Failure log excerpt') }}</summary>
                            <div class="border-t border-brand-ink/10 px-5 py-4 sm:px-6">
                                @if (($failure['summary'] ?? '') !== '')
                                    <p class="mb-3 font-mono text-xs text-rose-900">{{ $failure['summary'] }}</p>
                                @endif
                                @if (($failure['log_excerpt'] ?? '') !== '')
                                    <pre class="max-h-96 overflow-auto whitespace-pre-wrap break-words font-mono text-[11px] leading-5 text-brand-ink">{{ $failure['log_excerpt'] }}</pre>
                                @endif
                            </div>
                        </details>
                    @endif

                @endif
            </section>
        </div>
    </x-fleet-shell>
</div>
