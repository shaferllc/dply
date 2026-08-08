@php
    $stepCatalog = $pipelineStepCatalog ?? [];
    $stepTypeReference = $pipelineStepTypeReference ?? [];
    $hookCatalog = $pipelineHookCatalog ?? ['types' => [], 'presets' => []];
    $hiddenCount = collect($stepCatalog)->flatMap(fn ($g) => $g['entries'] ?? [])->where('visible', false)->count();
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';
@endphp

<section id="pipeline-step-catalog" class="scroll-mt-24 min-w-0">
    <div class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-book-open"
            :title="__('All pipeline steps')"
            :note="__('Build and release shortcuts. “Not in palette” entries are still addable here.')"
            :count="$hiddenCount > 0
                ? trans_choice('{1} :count hidden|[2,*] :count hidden', $hiddenCount, ['count' => $hiddenCount])
                : null"
        />

        <div class="divide-y divide-brand-ink/10">
            @foreach ($stepCatalog as $group)
                <div class="px-5 py-3 sm:px-6" wire:key="pipeline-catalog-{{ $group['id'] ?? 'group' }}">
                    <h4 class="text-xs font-semibold text-brand-ink">{{ __($group['label'] ?? '') }}</h4>
                    @if (filled($group['description'] ?? null))
                        <p class="mt-0.5 text-[11px] text-brand-moss">{{ __($group['description']) }}</p>
                    @endif
                    <ul class="mt-2 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10 bg-brand-sand/10">
                        @foreach ($group['entries'] ?? [] as $entry)
                            @php
                                $phase = $entry['phase'] ?? 'build';
                                $visible = (bool) ($entry['visible'] ?? true);
                            @endphp
                            <li
                                wire:key="catalog-entry-{{ $entry['catalog_key'] ?? $entry['label'] }}"
                                @class([
                                    'flex flex-col gap-2 px-3 py-2 sm:flex-row sm:items-center sm:justify-between',
                                    'opacity-75' => ! $visible,
                                ])
                            >
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <x-dynamic-component :component="$entry['icon'] ?? 'heroicon-o-plus'" class="h-3.5 w-3.5 shrink-0 text-brand-moss" />
                                        <span class="text-xs font-semibold text-brand-ink">{{ __($entry['label'] ?? '') }}</span>
                                        <span @class([
                                            'inline-flex rounded-full px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide',
                                            'bg-sky-100 text-sky-900' => $phase === 'build',
                                            'bg-emerald-100 text-emerald-900' => $phase === 'release',
                                        ])>{{ $phase === 'release' ? __('Release') : __('Build') }}</span>
                                        @if (filled($entry['requires_label'] ?? null))
                                            <span class="inline-flex rounded-full bg-brand-sand px-1.5 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                                                {{ $entry['requires_label'] }}
                                            </span>
                                        @endif
                                        @unless ($visible)
                                            <span class="inline-flex rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900">
                                                {{ __('Not in palette') }}
                                            </span>
                                        @endunless
                                    </div>
                                    @if (filled($entry['command_preview'] ?? null))
                                        <p class="mt-0.5 font-mono text-[11px] text-brand-moss">{{ $entry['command_preview'] }}</p>
                                    @endif
                                </div>
                                <button
                                    type="button"
                                    wire:click="addDeployPipelineStepFromPalette(@js($entry['type']), null, @js($phase), @js(filled($entry['custom_command'] ?? null) ? $entry['custom_command'] : null))"
                                    class="{{ $btnOutline }} shrink-0"
                                >
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" aria-hidden="true" />
                                    {{ __('Add') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>

    <details class="group border-b border-brand-ink/10">
        <summary class="flex cursor-pointer list-none items-center gap-2 px-5 py-2.5 marker:content-none sm:px-6">
            <x-heroicon-m-chevron-right class="h-3.5 w-3.5 shrink-0 text-brand-mist transition group-open:rotate-90" aria-hidden="true" />
            <span class="min-w-0">
                <span class="block text-xs font-semibold text-brand-ink">{{ __('Built-in step types') }}</span>
                <span class="mt-0.5 block text-[11px] text-brand-moss">{{ __('Raw types available in the step editor.') }}</span>
            </span>
        </summary>
        <div class="border-t border-brand-ink/10 px-5 py-3 sm:px-6">
            <p class="text-[11px] text-brand-moss">{{ __('Use “Add step” on Pipeline for npm scripts or commands not listed as shortcuts above.') }}</p>
            <div class="mt-2 overflow-x-auto">
                <table class="min-w-full text-left text-xs">
                    <thead>
                        <tr class="border-b border-brand-ink/10 text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                            <th class="py-1.5 pr-3">{{ __('Type') }}</th>
                            <th class="py-1.5 pr-3">{{ __('Default phase') }}</th>
                            <th class="py-1.5">{{ __('Command') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/8">
                        @foreach ($stepTypeReference as $row)
                            <tr wire:key="step-type-ref-{{ $row['type'] }}">
                                <td class="py-1.5 pr-3 font-medium text-brand-ink">{{ $row['label'] }}</td>
                                <td class="py-1.5 pr-3 text-brand-moss">
                                    {{ ($row['default_phase'] ?? 'build') === 'release' ? __('Release') : __('Build') }}
                                </td>
                                <td class="py-1.5 font-mono text-[11px] text-brand-moss">
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
        </div>
    </details>

    <div class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-bolt"
            :title="__('Hook types & shortcuts')"
            :note="__('Shell, webhook, and notification hooks for any dashed timeline slot.')"
        />

        <div class="px-5 py-3 sm:px-6">
            <p class="text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Types') }}</p>
            <ul class="mt-1.5 flex flex-wrap gap-1.5">
                @foreach ($hookCatalog['types'] ?? [] as $hookType)
                    <li class="inline-flex items-center gap-1 rounded-full border border-amber-200/80 bg-amber-50/80 px-2 py-1 text-[11px] font-semibold text-amber-950">
                        <x-dynamic-component :component="$hookType['icon'] ?? 'heroicon-o-bolt'" class="h-3.5 w-3.5" />
                        {{ __($hookType['label'] ?? '') }}
                    </li>
                @endforeach
            </ul>

            @if (($hookCatalog['presets'] ?? []) !== [])
                <p class="mt-3 text-[10px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Preset scripts') }}</p>
                <ul class="mt-1.5 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
                    @foreach ($hookCatalog['presets'] as $preset)
                        <li
                            wire:key="hook-preset-{{ $preset['label'] }}"
                            @class([
                                'flex flex-col gap-2 px-3 py-2 sm:flex-row sm:items-center sm:justify-between',
                                'opacity-75' => ! ($preset['visible'] ?? true),
                            ])
                        >
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-xs font-semibold text-brand-ink">{{ __($preset['label'] ?? '') }}</span>
                                    @if (filled($preset['requires_label'] ?? null))
                                        <span class="inline-flex rounded-full bg-brand-sand px-1.5 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-brand-ink/10">
                                            {{ $preset['requires_label'] }}
                                        </span>
                                    @endif
                                    @unless ($preset['visible'] ?? true)
                                        <span class="inline-flex rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900">
                                            {{ __('Not in palette') }}
                                        </span>
                                    @endunless
                                </div>
                                @if (filled($preset['script'] ?? null))
                                    <p class="mt-0.5 max-w-2xl truncate font-mono text-[11px] text-brand-moss" title="{{ $preset['script'] }}">{{ Str::limit($preset['script'], 120) }}</p>
                                @endif
                            </div>
                            <button
                                type="button"
                                wire:click="addDeployPipelineHookFromPreset(@js($preset))"
                                class="{{ $btnOutline }} shrink-0 !border-amber-200/80 !text-amber-950 hover:!bg-amber-50"
                            >
                                <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                {{ __('Add') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</section>
