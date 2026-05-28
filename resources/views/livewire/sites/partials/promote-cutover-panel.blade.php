@php
    $promote = is_array($site->meta['promote'] ?? null) ? $site->meta['promote'] : [];
    $cutoverStatus = (string) ($promote['cutover_status'] ?? '');
@endphp

@if ($cutoverStatus === 'pending_preview')
    @php
        $planner = app(\App\Services\Sites\Promote\SitePromotePlanner::class);
        $summary = $planner->summary($site);
        $steps = $planner->cutoverSteps($site);
    @endphp
    <section class="mb-6 overflow-hidden rounded-2xl border border-brand-sage/30 bg-brand-sage/5 shadow-sm">
        <div class="border-b border-brand-sage/20 px-6 py-5 sm:px-8">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-forest">{{ __('Standby promote') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Cutover playbook') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">
                        @if ($summary['source_site_name'])
                            {{ __('Promoted from :name on :server.', ['name' => $summary['source_site_name'], 'server' => $summary['source_server_name'] ?? __('source server')]) }}
                        @else
                            {{ __('This site was created as a preview-first standby clone.') }}
                        @endif
                    </p>
                </div>
                @if ($summary['preview_hostname'])
                    <div class="text-right text-xs">
                        <p class="font-semibold uppercase tracking-wide text-brand-moss">{{ __('Preview hostname') }}</p>
                        <p class="mt-0.5 font-mono text-sm text-brand-ink">{{ $summary['preview_hostname'] }}</p>
                    </div>
                @endif
            </div>
            @if ($summary['production_hostname'])
                <p class="mt-3 text-xs text-brand-moss">
                    {{ __('Production target') }}: <span class="font-mono font-semibold text-brand-ink">{{ $summary['production_hostname'] }}</span>
                </p>
            @endif
        </div>
        <ol class="divide-y divide-brand-sage/15 px-6 py-2 sm:px-8">
            @foreach ($steps as $index => $step)
                <li class="py-3 text-sm">
                    <span class="font-semibold text-brand-moss">{{ $index + 1 }}.</span>
                    <span class="text-brand-ink">{{ $step['text'] }}</span>
                    @if ($step['href'] && $step['link_label'])
                        <a href="{{ $step['href'] }}" wire:navigate class="mt-1 inline-block text-xs font-semibold text-brand-forest hover:underline">{{ $step['link_label'] }} →</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
