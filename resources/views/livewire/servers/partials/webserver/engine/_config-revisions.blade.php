@if ($config_selected_path !== null)
    @feature('workspace.webserver_config_diff')
        @if ($config_contents !== $config_original_contents)
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="{{ $webserverConfigSaveDiffOpen ? 'closeWebserverConfigSaveDiff' : 'openWebserverConfigSaveDiff' }}"
                    class="inline-flex items-center gap-1 rounded-md border border-violet-200 bg-violet-50 px-2.5 py-1 text-[11px] font-semibold text-violet-800 hover:bg-violet-100"
                >
                    <x-heroicon-o-arrows-right-left class="h-3 w-3" />
                    {{ $webserverConfigSaveDiffOpen ? __('Hide save diff') : __('Review diff before save') }}
                </button>
                <span class="text-[11px] text-brand-moss">{{ __('Compares the loaded file against your editor buffer.') }}</span>
            </div>
        @endif

        @if ($webserverConfigSaveDiffOpen && $webserverConfigSaveDiffText !== null)
            <div class="mt-3 rounded-xl border border-violet-200 bg-violet-50/40 p-3">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-violet-800">{{ __('Pending save diff') }}</p>
                <pre class="mt-2 max-h-48 overflow-auto rounded-lg bg-brand-ink p-3 font-mono text-xs leading-5 text-emerald-200">{{ $webserverConfigSaveDiffText !== '' ? $webserverConfigSaveDiffText : __('(no differences)') }}</pre>
            </div>
        @endif

        @if ($webserverConfigDiffText !== null)
            <div class="mt-3 rounded-xl border border-brand-ink/10 bg-white p-3">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-medium text-brand-ink">{{ $webserverConfigDiffHeader }}</p>
                    <button type="button" wire:click="closeWebserverConfigRevisionDiff" class="text-[11px] font-medium text-brand-moss hover:text-brand-ink">
                        {{ __('Close diff') }}
                    </button>
                </div>
                <pre class="mt-2 max-h-[40vh] overflow-auto rounded-lg bg-brand-ink p-3 font-mono text-xs leading-5 text-emerald-200">{{ $webserverConfigDiffText !== '' ? $webserverConfigDiffText : __('(no differences)') }}</pre>
            </div>
        @endif

        @if ($webserverConfigRevisions->isNotEmpty())
            <div class="mt-3 rounded-xl border border-brand-ink/10 bg-white">
                <div class="flex items-center justify-between border-b border-brand-ink/10 px-3 py-2">
                    <span class="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                        <x-heroicon-o-clock class="h-3 w-3" />
                        {{ __('Saved revisions') }}
                    </span>
                    <button type="button" wire:click="toggleWebserverConfigCompareMode" class="text-[10px] font-medium text-brand-moss hover:text-brand-ink">
                        {{ $webserverConfigCompareMode ? __('Cancel compare') : __('Compare two') }}
                    </button>
                </div>

                @if ($webserverConfigDriftDetected)
                    <div class="border-b border-amber-200 bg-amber-50/70 px-3 py-2 text-[11px] text-amber-900">
                        {{ __('Live file differs from the latest saved revision — it may have been edited outside Dply.') }}
                    </div>
                @endif

                <ul class="max-h-56 divide-y divide-brand-ink/5 overflow-auto text-xs">
                    @foreach ($webserverConfigRevisions as $rev)
                        @php
                            $isCurrent = $webserverConfigCurrentRevisionId === $rev->id;
                            $compareA = $webserverConfigCompareA === $rev->id;
                            $compareB = $webserverConfigCompareB === $rev->id;
                        @endphp
                        <li class="flex items-start justify-between gap-3 px-3 py-2 {{ $isCurrent ? 'bg-emerald-50/40' : '' }}" wire:key="ws-rev-{{ $rev->id }}">
                            <div class="min-w-0">
                                <p class="font-medium text-brand-ink">
                                    {{ optional($rev->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i') }}
                                    @if ($isCurrent)
                                        <span class="ml-1 rounded-full bg-emerald-50 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">{{ __('Current') }}</span>
                                    @endif
                                </p>
                                @if ($rev->user)
                                    <p class="text-[10px] text-brand-moss">{{ $rev->user->name }}</p>
                                @endif
                                @if ($rev->summary)
                                    <p class="mt-0.5 text-[10px] italic text-brand-ink/75">{{ $rev->summary }}</p>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                @if ($webserverConfigCompareMode)
                                    <button type="button" wire:click="selectWebserverConfigRevisionForCompare('{{ $rev->id }}')" class="text-[10px] font-medium text-brand-ink hover:underline">
                                        {{ $compareA ? __('Picked A') : ($compareB ? __('Picked B') : __('Select')) }}
                                    </button>
                                @else
                                    <button type="button" wire:click="showWebserverConfigRevisionDiff('{{ $rev->id }}')" class="text-[10px] font-medium text-brand-moss hover:text-brand-ink">{{ __('Diff') }}</button>
                                    <button type="button" wire:click="loadWebserverConfigRevision('{{ $rev->id }}')" class="text-[10px] font-medium text-brand-moss hover:text-brand-ink">{{ __('Load') }}</button>
                                    <button type="button" wire:click="rollbackWebserverConfigRevision('{{ $rev->id }}')" class="text-[10px] font-semibold text-brand-forest hover:underline">{{ __('Rollback') }}</button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($webserverConfigRevisions->count() >= $webserverConfigRevisionsLimit)
                    <div class="border-t border-brand-ink/10 px-3 py-2">
                        <button type="button" wire:click="showOlderWebserverConfigRevisions" class="text-[10px] font-medium text-brand-moss hover:text-brand-ink">
                            {{ __('Show older revisions') }}
                        </button>
                    </div>
                @endif
            </div>
        @endif
    @endfeature
@endif
