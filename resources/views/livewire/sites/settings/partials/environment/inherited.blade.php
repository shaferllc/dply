    {{-- Workspace-inherited preview. Read-only here; managed at the project
         level. Placed above the per-key list so operators see what they can
         override before scanning the cache. --}}
    @if ($workspaceVariables->isNotEmpty())
        <details class="{{ $card }}">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-link class="h-4 w-4 text-brand-moss" />
                    <span class="text-sm font-semibold text-brand-ink">{{ __('Inherited from project workspace') }}</span>
                    <span class="rounded-full bg-brand-sand/40 px-2 py-0.5 text-[11px] font-semibold text-brand-moss">
                        {{ trans_choice('{1} :count variable|[2,*] :count variables', $workspaceVariables->count(), ['count' => $workspaceVariables->count()]) }}
                    </span>
                </div>
                <span class="text-[11px] text-brand-mist">{{ __('Click to expand') }}</span>
            </summary>
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($workspaceVariables->sortBy('env_key') as $wsVar)
                    <li class="flex items-center justify-between gap-3 px-6 py-2.5 sm:px-8" wire:key="ws-var-{{ $wsVar->id }}">
                        <span class="font-mono text-sm text-brand-ink">{{ $wsVar->env_key }}</span>
                        <span class="text-[11px] text-brand-mist">
                            @if ((bool) ($wsVar->is_secret ?? false))
                                {{ __('Secret — managed in project settings') }}
                            @else
                                {{ __('Project-managed — override by adding the same key here') }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
