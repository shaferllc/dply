@php
    $checkKey = $check['key'] ?? '';
    $severity = $check['severity'] ?? 'info';
    $opensSshKeyModal = in_array($checkKey, ['user_ssh_keys', 'user_ssh_key_defaults'], true);

    // A passing check is one line: nobody needs "The selected provider
    // credential is ready for this request." on its own row. Anything not
    // passing keeps its detail wrapped underneath — that's the row you stopped
    // to read.
    $isPassing = $severity === 'info' && ! ($check['blocking'] ?? false);

    $statusLabel = ($check['blocking'] ?? false)
        ? __('Blocking')
        : match ($severity) {
            'warning' => __('Warning'),
            default => __('Ready'),
        };
@endphp

<div class="flex items-start gap-2 border-l-2 px-3 py-1.5 sm:px-4 {{ $preflightItemClasses($severity) }}">
    @if ($severity === 'info')
        <x-heroicon-s-check-circle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" aria-hidden="true" />
    @elseif ($severity === 'warning')
        <x-heroicon-s-exclamation-triangle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-amber-600" aria-hidden="true" />
    @else
        <x-heroicon-s-x-circle class="mt-0.5 h-3.5 w-3.5 shrink-0 text-rose-600" aria-hidden="true" />
    @endif

    <div class="min-w-0 flex-1">
        @if ($isPassing)
            {{-- One line: label, hairline, detail truncated to whatever width is
                 left. `title` is upgraded to the styled bubble by
                 resources/js/tooltip.js when the text is actually clipped. --}}
            <div class="flex min-w-0 items-center gap-2">
                <p class="shrink-0 text-xs font-semibold">{{ $check['label'] }}</p>
                <span class="h-3 w-px shrink-0 bg-current opacity-20" aria-hidden="true"></span>
                <p class="min-w-0 flex-1 truncate text-xs opacity-80" title="{{ $check['detail'] }}">{{ $check['detail'] }}</p>
            </div>
        @elseif ($opensSshKeyModal)
            {{-- The whole heading/detail block is a click target so operators can
                 punch into the SSH-key modal from the row itself, not just from
                 the button below. --}}
            <button
                type="button"
                x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                class="block w-full rounded text-left transition-colors hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-400/60"
            >
                <p class="text-xs font-semibold underline-offset-4 hover:underline">{{ $check['label'] }}</p>
                <p class="mt-0.5 text-xs leading-5 opacity-90">{{ $check['detail'] }}</p>
            </button>
        @else
            <p class="text-xs font-semibold">{{ $check['label'] }}</p>
            <p class="mt-0.5 text-xs leading-5 opacity-90">{{ $check['detail'] }}</p>
        @endif

        @if ($checkKey === 'user_ssh_keys')
            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-rose-200/60 pt-1.5">
                <button
                    type="button"
                    x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                    class="inline-flex h-6 w-fit items-center justify-center gap-1 rounded-md bg-sky-600 px-2 text-xs font-semibold text-white shadow-sm transition hover:bg-sky-700"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Add SSH key') }}
                </button>
                <a
                    href="{{ route('profile.ssh-keys', ['source' => 'servers.create', 'return_to' => 'servers.create']) }}"
                    wire:navigate
                    class="text-2xs font-medium underline decoration-current/40 underline-offset-2 opacity-80 hover:opacity-100"
                >
                    {{ __('Profile SSH keys page') }}
                </a>
            </div>
        @elseif ($checkKey === 'user_ssh_key_defaults')
            <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-amber-200/60 pt-1.5">
                <button
                    type="button"
                    x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                    class="inline-flex h-6 w-fit items-center justify-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 text-brand-moss" aria-hidden="true" />
                    {{ __('Adjust SSH keys') }}
                </button>
                <a
                    href="{{ route('profile.ssh-keys', ['source' => 'servers.create', 'return_to' => 'servers.create']) }}"
                    wire:navigate
                    class="text-2xs font-medium underline decoration-current/40 underline-offset-2 opacity-80 hover:opacity-100"
                >
                    {{ __('Open profile') }}
                </a>
            </div>
        @endif
    </div>

    <span class="shrink-0 text-2xs font-semibold uppercase tracking-[0.14em] opacity-70">{{ $statusLabel }}</span>
</div>
