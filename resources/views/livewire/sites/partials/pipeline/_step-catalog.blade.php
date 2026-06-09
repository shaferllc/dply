@php
    $stepCatalog = $pipelineStepCatalog ?? [];
    $stepTypeReference = $pipelineStepTypeReference ?? [];
    $hookCatalog = $pipelineHookCatalog ?? ['types' => [], 'presets' => []];
    $hiddenCount = collect($stepCatalog)->flatMap(fn ($g) => $g['entries'] ?? [])->where('visible', false)->count();
@endphp

<section id="pipeline-step-catalog" class="scroll-mt-24 space-y-6">
    <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Step catalog') }}</p>
                <h3 class="mt-1 text-base font-semibold text-brand-ink">{{ __('All pipeline steps') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Every shortcut Dply supports for build and release phases. Entries marked “Not in palette” are hidden on the Pipeline tab for this site’s runtime but you can still add them here.') }}
                </p>
            </div>
            @if ($hiddenCount > 0)
                <span class="inline-flex shrink-0 items-center rounded-full bg-brand-sand px-2.5 py-1 text-[11px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                    {{ trans_choice('{1} :count hidden from palette|[2,*] :count hidden from palette', $hiddenCount, ['count' => $hiddenCount]) }}
                </span>
            @endif
        </div>

        <div class="mt-6 space-y-8">
            @foreach ($stepCatalog as $group)
                <div wire:key="pipeline-catalog-{{ $group['id'] ?? 'group' }}">
                    <h4 class="text-sm font-semibold text-brand-ink">{{ __($group['label'] ?? '') }}</h4>
                    @if (filled($group['description'] ?? null))
                        <p class="mt-0.5 text-xs text-brand-moss">{{ __($group['description']) }}</p>
                    @endif
                    <ul class="mt-3 divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10 bg-brand-cream/30">
                        @foreach ($group['entries'] ?? [] as $entry)
                            @php
                                $phase = $entry['phase'] ?? 'build';
                                $visible = (bool) ($entry['visible'] ?? true);
                            @endphp
                            <li
                                wire:key="catalog-entry-{{ $entry['catalog_key'] ?? $entry['label'] }}"
                                @class([
                                    'flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between',
                                    'opacity-75' => ! $visible,
                                ])
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-dynamic-component :component="$entry['icon'] ?? 'heroicon-o-plus'" class="h-4 w-4 shrink-0 text-brand-moss" />
                                        <span class="text-sm font-semibold text-brand-ink">{{ __($entry['label'] ?? '') }}</span>
                                        <span @class([
                                            'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                            'bg-sky-100 text-sky-900' => $phase === 'build',
                                            'bg-emerald-100 text-emerald-900' => $phase === 'release',
                                        ])>{{ $phase === 'release' ? __('Release') : __('Build') }}</span>
                                        @if (filled($entry['requires_label'] ?? null))
                                            <span class="inline-flex rounded-full bg-brand-sand px-2 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                                                {{ $entry['requires_label'] }}
                                            </span>
                                        @endif
                                        @unless ($visible)
                                            <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-900">
                                                {{ __('Not in palette') }}
                                            </span>
                                        @endunless
                                    </div>
                                    @if (filled($entry['command_preview'] ?? null))
                                        <p class="mt-1.5 font-mono text-xs text-brand-moss">{{ $entry['command_preview'] }}</p>
                                    @endif
                                </div>
                                <button
                                    type="button"
                                    wire:click="addDeployPipelineStepFromPalette(@js($entry['type']), null, @js($phase), @js(filled($entry['custom_command'] ?? null) ? $entry['custom_command'] : null))"
                                    class="inline-flex shrink-0 items-center justify-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm hover:border-brand-sage hover:text-brand-sage"
                                >
                                    <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                                    {{ __('Add to pipeline') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>

    <details class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
        <summary class="cursor-pointer list-none">
            <h3 class="inline text-base font-semibold text-brand-ink">{{ __('Built-in step types') }}</h3>
            <span class="ml-2 text-sm text-brand-moss">— {{ __('raw types available in the step editor') }}</span>
        </summary>
        <p class="mt-2 text-sm text-brand-moss">{{ __('Use “Add step” on the Pipeline tab for npm scripts or commands not listed as shortcuts above.') }}</p>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-brand-ink/10 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">
                        <th class="py-2 pr-4">{{ __('Type') }}</th>
                        <th class="py-2 pr-4">{{ __('Default phase') }}</th>
                        <th class="py-2">{{ __('Command') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/8">
                    @foreach ($stepTypeReference as $row)
                        <tr wire:key="step-type-ref-{{ $row['type'] }}">
                            <td class="py-2.5 pr-4 font-medium text-brand-ink">{{ $row['label'] }}</td>
                            <td class="py-2.5 pr-4 text-brand-moss">
                                {{ ($row['default_phase'] ?? 'build') === 'release' ? __('Release') : __('Build') }}
                            </td>
                            <td class="py-2.5 font-mono text-xs text-brand-moss">
                                @if ($row['needs_custom_command'] ?? false)
                                    <span class="text-brand-mist">{{ __('You supply script name or shell command') }}</span>
                                @elseif (filled($row['command_preview'] ?? null))
                                    {{ $row['command_preview'] }}
                                @else
                                    <span class="text-brand-mist">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </details>

    <div class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8">
        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Hooks') }}</p>
        <h3 class="mt-1 text-base font-semibold text-brand-ink">{{ __('Hook types & shortcuts') }}</h3>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Shell, webhook, and notification hooks can be placed on any dashed slot in the timeline.') }}</p>

        <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Types') }}</p>
        <ul class="mt-2 flex flex-wrap gap-2">
            @foreach ($hookCatalog['types'] ?? [] as $hookType)
                <li class="inline-flex items-center gap-1.5 rounded-full border border-amber-200/80 bg-amber-50/80 px-3 py-1.5 text-xs font-semibold text-amber-950">
                    <x-dynamic-component :component="$hookType['icon'] ?? 'heroicon-o-bolt'" class="h-4 w-4" />
                    {{ __($hookType['label'] ?? '') }}
                </li>
            @endforeach
        </ul>

        @if (($hookCatalog['presets'] ?? []) !== [])
            <p class="mt-5 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Preset scripts') }}</p>
            <ul class="mt-3 divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10">
                @foreach ($hookCatalog['presets'] as $preset)
                    <li
                        wire:key="hook-preset-{{ $preset['label'] }}"
                        @class([
                            'flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between',
                            'opacity-75' => ! ($preset['visible'] ?? true),
                        ])
                    >
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-sm font-semibold text-brand-ink">{{ __($preset['label'] ?? '') }}</span>
                                @if (filled($preset['requires_label'] ?? null))
                                    <span class="inline-flex rounded-full bg-brand-sand px-2 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                                        {{ $preset['requires_label'] }}
                                    </span>
                                @endif
                                @unless ($preset['visible'] ?? true)
                                    <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-900">
                                        {{ __('Not in palette') }}
                                    </span>
                                @endunless
                            </div>
                            @if (filled($preset['script'] ?? null))
                                <p class="mt-1.5 max-w-2xl truncate font-mono text-xs text-brand-moss" title="{{ $preset['script'] }}">{{ Str::limit($preset['script'], 120) }}</p>
                            @endif
                        </div>
                        <button
                            type="button"
                            wire:click="addDeployPipelineHookFromPreset(@js($preset))"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-200/80 bg-white px-3 py-2 text-xs font-semibold text-amber-950 hover:bg-amber-50"
                        >
                            <x-heroicon-o-plus class="h-4 w-4" />
                            {{ __('Add hook') }}
                        </button>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
