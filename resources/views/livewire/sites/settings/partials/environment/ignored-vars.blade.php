    {{-- Ignored variables — the operator marked these as intentionally unset,
         so they don't count toward "missing required". One-click un-ignore. --}}
    @if ($canIgnoreEnv && $ignoredEnvKeys !== [])
        <div class="{{ $card }}">
            <x-workspace-panel-head
                class="border-b border-brand-ink/10"
                icon="heroicon-o-no-symbol"
                :title="__('Ignored variables')"
                :note="__('These required variables are ignored for this site — they won\'t block deploys.')"
                :count="(string) count($ignoredEnvKeys)"
            />
            <div class="flex flex-wrap gap-1.5 px-5 py-2.5 sm:px-6">
                @foreach ($ignoredEnvKeys as $ik)
                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 font-mono text-xs font-semibold text-brand-moss ring-1 ring-inset ring-brand-ink/10">
                        {{ $ik }}
                        <button type="button" wire:click="unignoreEnvKey('{{ $ik }}')" class="text-brand-mist hover:text-rose-700" title="{{ __('Un-ignore') }}" aria-label="{{ __('Un-ignore :key', ['key' => $ik]) }}">
                            <x-heroicon-o-x-mark class="h-3 w-3" />
                        </button>
                    </span>
                @endforeach
            </div>
        </div>
    @endif
