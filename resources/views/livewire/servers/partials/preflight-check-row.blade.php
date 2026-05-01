<div class="rounded-xl border px-4 py-3 {{ $preflightItemClasses($check['severity']) }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold">{{ $check['label'] }}</p>
            <p class="mt-1 text-sm leading-6">{{ $check['detail'] }}</p>
            @if (($check['key'] ?? '') === 'user_ssh_keys')
                <div class="mt-3 flex flex-col gap-2 border-t border-rose-200/60 pt-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                        class="inline-flex w-fit items-center justify-center gap-2 rounded-lg bg-sky-600 px-3.5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-700"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Add SSH key') }}
                    </button>
                    <p class="text-xs leading-5 text-slate-600">
                        <a
                            href="{{ route('profile.ssh-keys', ['source' => 'servers.create', 'return_to' => 'servers.create']) }}"
                            wire:navigate
                            class="font-medium text-slate-700 underline decoration-slate-400/80 underline-offset-2 hover:text-slate-900"
                        >
                            {{ __('Profile SSH keys page') }}
                        </a>
                        <span class="text-slate-500">{{ __('— full list and deploy options') }}</span>
                    </p>
                </div>
            @elseif (($check['key'] ?? '') === 'user_ssh_key_defaults')
                <div class="mt-3 flex flex-col gap-2 border-t border-amber-200/60 pt-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                        class="inline-flex w-fit items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-800 shadow-sm transition hover:bg-slate-50"
                    >
                        <x-heroicon-o-key class="h-4 w-4 shrink-0 text-slate-600" aria-hidden="true" />
                        {{ __('Adjust SSH keys') }}
                    </button>
                    <p class="text-xs leading-5 text-slate-600">
                        <a
                            href="{{ route('profile.ssh-keys', ['source' => 'servers.create', 'return_to' => 'servers.create']) }}"
                            wire:navigate
                            class="font-medium text-slate-700 underline decoration-slate-400/80 underline-offset-2 hover:text-slate-900"
                        >
                            {{ __('Open profile') }}
                        </a>
                    </p>
                </div>
            @endif
        </div>
        <span class="shrink-0 text-[11px] font-semibold uppercase tracking-[0.16em]">
            {{ $check['blocking'] ? __('Blocking') : match ($check['severity']) {
                'warning' => __('Warning'),
                default => __('Ready'),
            } }}
        </span>
    </div>
</div>
