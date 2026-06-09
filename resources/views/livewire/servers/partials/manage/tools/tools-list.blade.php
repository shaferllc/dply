<div class="dply-card overflow-hidden">
    @php
        $actionPrimary = 'inline-flex shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50';
        $actionSecondary = 'inline-flex shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:border-brand-sage/30 hover:bg-brand-sage/8 focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-1 disabled:cursor-not-allowed disabled:opacity-50';
        $actionBusy = 'inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-sage/35 bg-brand-sage/12 px-3 py-1.5 text-xs font-semibold text-brand-forest';
        $actionChip = 'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full border border-brand-ink/10 bg-brand-cream/40 px-2.5 py-1 text-[11px] font-semibold text-brand-forest transition hover:border-brand-sage/35 hover:bg-brand-sage/10 hover:text-brand-ink';
        $actionMuted = 'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full bg-brand-sand/55 px-2.5 py-1 text-[11px] font-medium text-brand-moss ring-1 ring-brand-ink/10';
    @endphp
    <ul role="list" class="divide-y divide-brand-ink/10">
        @foreach ($catalogRows as $tool)
            @php
                $presentKey = $tool['present_action_key'] ?? null;
                $installKey = $tool['action_key'] ?? null;
                $presentBusy = $toolActionIsActive($presentKey);
                $installBusy = $toolActionIsActive($installKey);
                $toolBusy = $presentBusy || $installBusy;
                $isMise = ($tool['slug'] ?? '') === 'mise';

                $secondaryLinks = collect([
                    'runtimes' => $isMise ? ['label' => __('Runtimes'), 'icon' => 'heroicon-o-cpu-chip', 'action' => 'setToolsPanel', 'panel' => 'runtimes'] : null,
                    'source_control' => $tool['source_control_url'] ? ['label' => __('Source control'), 'icon' => 'heroicon-o-code-bracket-square', 'href' => $tool['source_control_url']] : null,
                    'caches' => $tool['caches_url'] ? ['label' => __('Caches'), 'icon' => 'heroicon-o-bolt', 'href' => $tool['caches_url']] : null,
                    'run' => $tool['run_url'] ? ['label' => __('Run'), 'icon' => 'heroicon-o-play-circle', 'href' => $tool['run_url']] : null,
                ])->filter()->values();
                $hasDockerLink = filled($tool['docker_url'] ?? null);
                $showDetected = $tool['present']
                    && ! $tool['show_present_action']
                    && ! $tool['show_action']
                    && $secondaryLinks->isEmpty()
                    && ! $isMise
                    && $opsReady;
            @endphp
            <li
                wire:key="manage-tool-row-{{ $tool['slug'] }}"
                @class([
                    'flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-start sm:justify-between sm:gap-4',
                    'bg-brand-sage/5' => $toolBusy,
                ])
            >
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <x-dynamic-component :component="$tool['icon']" class="h-4 w-4 shrink-0 text-brand-moss" />
                        <p class="font-medium text-brand-ink">{{ $tool['label'] }}</p>
                        @if ($tool['docs_url'])
                            <a href="{{ $tool['docs_url'] }}" target="_blank" rel="noopener noreferrer" class="text-[11px] text-brand-moss hover:text-brand-ink">
                                {{ __('Docs') }}
                            </a>
                        @endif
                    </div>

                    <div class="mt-1.5 flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-medium ring-1 {{ $statusTone($tool['status_tone']) }}">
                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $statusBadgeDot($tool['status_tone']) }}"></span>
                            {{ $tool['status_label'] }}
                        </span>
                        @if (filled($tool['version'] ?? null))
                            <span class="font-mono text-[11px] text-brand-moss">{{ $tool['version'] }}</span>
                        @endif
                    </div>

                    @if ($tool['present'] && $tool['slug'] === 'git')
                        @include('livewire.servers.partials.manage.tools.git-identity', ['tool' => $tool])
                    @endif
                </div>

                <div class="flex flex-col items-start gap-1.5 sm:items-end">
                    <div class="flex items-center">
                        @if ($presentBusy)
                            <span @class([$actionBusy])>
                                <x-spinner variant="forest" size="sm" />
                                {{ $activeToolActionOps[$presentKey]['message'] ?? __('Updating…') }}
                            </span>
                        @elseif ($tool['show_present_action'] && $tool['present_action'] && $opsReady && ! $isDeployer)
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $tool['present_action_key'] }}'], @js($tool['present_action']['label']), @js($tool['present_action']['confirm']), @js($tool['present_action']['label']), false)"
                                wire:loading.attr="disabled"
                                wire:target="confirmActionModal"
                                @class([$actionSecondary])
                            >
                                {{ $tool['present_action']['label'] }}
                            </button>
                        @elseif ($installBusy)
                            <span @class([$actionBusy])>
                                <x-spinner variant="forest" size="sm" />
                                {{ $activeToolActionOps[$installKey]['message'] ?? __('Installing…') }}
                            </span>
                        @elseif ($tool['show_action'] && $tool['action'] && $opsReady && ! $isDeployer)
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $tool['action_key'] }}'], @js($tool['action']['label']), @js($tool['action']['confirm']), @js($tool['action']['label']), false)"
                                wire:loading.attr="disabled"
                                wire:target="confirmActionModal"
                                @class([$actionPrimary])
                            >
                                <x-heroicon-o-arrow-down-tray class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ $tool['action']['label'] }}
                            </button>
                        @elseif (! $opsReady)
                            <span @class([$actionMuted])>{{ __('SSH not ready') }}</span>
                        @elseif ($showDetected)
                            <span @class([$actionMuted])>
                                <x-heroicon-o-check class="h-3 w-3 shrink-0" aria-hidden="true" />
                                {{ __('Detected') }}
                            </span>
                        @endif
                    </div>

                    @if ($secondaryLinks->isNotEmpty() || $hasDockerLink)
                        <div class="flex flex-wrap items-center gap-1.5 sm:justify-end">
                            @foreach ($secondaryLinks as $link)
                                @if (isset($link['action']))
                                    <button
                                        type="button"
                                        wire:click="setToolsPanel('{{ $link['panel'] }}')"
                                        @class([$actionChip])
                                    >
                                        <x-dynamic-component :component="$link['icon']" class="h-3 w-3 shrink-0" />
                                        {{ $link['label'] }}
                                        <x-heroicon-o-chevron-right class="h-3 w-3 shrink-0 opacity-60" aria-hidden="true" />
                                    </button>
                                @else
                                    <a href="{{ $link['href'] }}" wire:navigate @class([$actionChip])>
                                        <x-dynamic-component :component="$link['icon']" class="h-3 w-3 shrink-0" />
                                        {{ $link['label'] }}
                                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 shrink-0 opacity-60" aria-hidden="true" />
                                    </a>
                                @endif
                            @endforeach
                            @if ($hasDockerLink)
                                @feature('workspace.docker')
                                    <a href="{{ $tool['docker_url'] }}" wire:navigate @class([$actionChip])>
                                        <x-heroicon-o-square-3-stack-3d class="h-3 w-3 shrink-0" />
                                        {{ __('Docker') }}
                                        <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 shrink-0 opacity-60" aria-hidden="true" />
                                    </a>
                                @endfeature
                            @endif
                        </div>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</div>
