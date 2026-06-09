<div>
    <div class="border-b border-brand-ink/10 bg-white">
        <div class="dply-page-shell py-8">
            <x-page-header
                :title="__('Standby blueprints')"
                :description="__('Honest failover playbooks across Edge, Cloud, and BYO — inventory-aware steps with deep links. Not full HA; actionable cutover runbooks.')"
                doc-route="docs.markdown"
                doc-slug="edge-delivery"
                flush
            >
                <x-slot name="actions">
                    <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('Launchpad') }}</a>
                </x-slot>
            </x-page-header>
        </div>
    </div>

    <div class="min-h-[50vh] bg-brand-cream py-10">
        <div class="dply-page-shell max-w-3xl">
            @if ($playbook === null)
                <section class="space-y-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Choose a scenario') }}</h2>
                    <p class="text-sm text-brand-moss">{{ __('Each blueprint adapts to your org inventory — hybrid Edge stacks, BYO servers, and custom domains.') }}</p>

                    <ul class="mt-4 space-y-3">
                        @foreach ($catalog as $item)
                            <li>
                                <button
                                    type="button"
                                    wire:click="selectBlueprint('{{ $item['key'] }}')"
                                    @class([
                                        'w-full rounded-2xl border p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md',
                                        'border-brand-sage/40 bg-white ring-1 ring-brand-sage/20' => $item['available'],
                                        'border-brand-ink/10 bg-brand-sand/20 opacity-90' => ! $item['available'],
                                    ])
                                >
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-base font-semibold text-brand-ink">{{ $item['title'] }}</h3>
                                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $item['summary'] }}</p>
                                        </div>
                                        @if ($item['available'])
                                            <span class="rounded-full bg-brand-sage/15 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-sage/25">{{ __('Ready') }}</span>
                                        @else
                                            <span class="rounded-full bg-brand-ink/5 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Needs setup') }}</span>
                                        @endif
                                    </div>
                                    @if (! $item['available'] && $item['unavailable_reason'])
                                        <p class="mt-3 text-xs text-brand-moss">{{ $item['unavailable_reason'] }}</p>
                                    @endif
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @else
                <section class="space-y-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <button type="button" wire:click="backToCatalog" class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('← All blueprints') }}</button>
                            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ $playbook['title'] }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">{{ $playbook['summary'] }}</p>
                        </div>
                        @if ($playbook['doc_slug'])
                            <x-docs-link :slug="$playbook['doc_slug']">{{ __('Related docs') }}</x-docs-link>
                        @endif
                    </div>

                    @if (! $playbook['available'])
                        <div class="rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                            <p class="font-medium">{{ __('Inventory gap') }}</p>
                            <p class="mt-1">{{ $playbook['unavailable_reason'] }}</p>
                            <p class="mt-2 text-xs">{{ __('Steps below are still useful as a template while you wire up the prerequisites.') }}</p>
                        </div>
                    @endif

                    @if (count($playbook['gaps']) > 0)
                        <div class="rounded-xl border border-amber-200/80 bg-amber-50/50 px-4 py-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-900">{{ __('Before cutover') }}</p>
                            <ul class="mt-2 space-y-1 text-sm text-amber-950">
                                @foreach ($playbook['gaps'] as $gap)
                                    <li class="flex gap-2"><span aria-hidden="true">•</span><span>{{ $gap }}</span></li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (count($playbook['resources']) > 0)
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Your inventory') }}</h3>
                            <ul class="mt-3 space-y-2">
                                @foreach ($playbook['resources'] as $resource)
                                    <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 text-sm">
                                        <div>
                                            <span class="font-semibold text-brand-ink">{{ $resource['label'] }}</span>
                                            @if ($resource['meta'])
                                                <p class="mt-0.5 text-xs text-brand-moss">{{ $resource['meta'] }}</p>
                                            @endif
                                        </div>
                                        @if ($resource['href'])
                                            <a href="{{ $resource['href'] }}" wire:navigate class="text-xs font-semibold text-brand-sage hover:text-brand-forest">{{ __('Open') }} →</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div>
                        <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Playbook steps') }}</h3>
                        <ol class="mt-3 space-y-3">
                            @foreach ($playbook['steps'] as $index => $step)
                                <li class="flex gap-3 rounded-2xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-brand-sand/60 text-xs font-bold text-brand-forest ring-1 ring-brand-ink/10">{{ $index + 1 }}</span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm leading-relaxed text-brand-ink">{{ $step['text'] }}</p>
                                        @if ($step['href'] && $step['link_label'])
                                            <a href="{{ $step['href'] }}" wire:navigate class="mt-2 inline-flex text-xs font-semibold text-brand-sage hover:text-brand-forest">{{ $step['link_label'] }} →</a>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    @feature('surface.marketplace')
                        <p class="text-sm text-brand-moss">
                            {{ __('Import the matching runbook from the marketplace for project-level notes:') }}
                            <a href="{{ route('marketplace.index') }}" wire:navigate class="font-semibold text-brand-sage hover:text-brand-forest">{{ __('Runbook marketplace') }}</a>
                        </p>
                    @endfeature
                </section>
            @endif
        </div>
    </div>
</div>
