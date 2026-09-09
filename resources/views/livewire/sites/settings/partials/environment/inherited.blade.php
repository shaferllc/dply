    {{-- Workspace-inherited preview. Read-only here; managed at the project
         level. Collapsed by default so the site .env stays the primary list. --}}
    @if ($workspaceVariables->isNotEmpty())
        <section class="{{ $card }}" x-data="{ open: false }">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3.5 sm:px-6">
                <button type="button" class="flex min-w-0 flex-wrap items-center gap-2 text-left" x-on:click="open = ! open" :aria-expanded="open">
                    <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform" x-bind:class="open && 'rotate-90'" />
                    <x-heroicon-o-link class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Inherited from project') }}</h2>
                    <span class="inline-flex items-center rounded-full bg-white px-2 py-0.5 text-xs font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">
                        {{ trans_choice('{1} :count variable|[2,*] :count variables', $workspaceVariables->count(), ['count' => $workspaceVariables->count()]) }}
                    </span>
                </button>
                <p class="mt-1 text-xs leading-relaxed text-brand-moss">{{ __('Read-only here. Edit these on the project.') }}</p>
            </div>
            <ul class="divide-y divide-brand-ink/8" x-show="open" x-cloak>
                @foreach ($workspaceVariables->sortBy('env_key') as $wsVar)
                    <li class="px-5 py-2.5 transition-colors hover:bg-brand-sand/15 sm:px-6" wire:key="ws-var-{{ $wsVar->id }}">
                        <div class="flex items-center gap-3">
                            <div class="flex min-w-0 flex-1 items-center gap-2.5">
                                <div class="flex min-w-0 shrink-0 items-center gap-1 sm:w-64">
                                    <span class="truncate font-mono text-xs font-semibold text-brand-ink" title="{{ $wsVar->env_key }}">{{ $wsVar->env_key }}</span>
                                </div>
                                <p class="min-w-0 flex-1 truncate font-mono text-xs text-brand-mist">
                                    @if ((bool) ($wsVar->is_secret ?? false))
                                        {{ str_repeat('•', 8) }}
                                    @else
                                        {{ __('project-managed') }}
                                    @endif
                                </p>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
