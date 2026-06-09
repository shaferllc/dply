    {{-- Ignored variables — the operator marked these as intentionally unset,
         so they don't count toward "missing required". One-click un-ignore. --}}
    @if ($canIgnoreEnv && $ignoredEnvKeys !== [])
        <div class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 bg-brand-sand/20 px-5 py-4">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Ignored variables') }}</p>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('These required variables are ignored for this site — they won\'t block deploys.') }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($ignoredEnvKeys as $ik)
                            <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 font-mono text-[11px] font-semibold text-brand-moss ring-1 ring-inset ring-brand-ink/10">
                                {{ $ik }}
                                <button type="button" wire:click="unignoreEnvKey('{{ $ik }}')" class="text-brand-mist hover:text-rose-700" title="{{ __('Un-ignore') }}" aria-label="{{ __('Un-ignore :key', ['key' => $ik]) }}">
                                    <x-heroicon-o-x-mark class="h-3 w-3" />
                                </button>
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
