<div class="{{ $card }}">
    <div class="flex min-w-0 items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
        <x-icon-badge>
            <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Inspect') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Inspect crontab') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                {{ __('Read-only: shows the real crontab file for that Linux user. Dply uses the SSH login user for “crontab -l”; other users need “sudo crontab -u … -l” (passwordless sudo).') }}
            </p>
        </div>
    </div>
    <div class="space-y-4 p-6 sm:p-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="min-w-0 flex-1">
                <x-input-label for="inspect_crontab_user" value="{{ __('Linux user') }}" />
                @php $crontabUserChoices = collect($crontabInspectUserChoices); @endphp
                <select
                    id="inspect_crontab_user"
                    wire:model.live="inspect_crontab_user"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                >
                    {{-- Keep a custom/unknown current value selectable. --}}
                    @if (trim($inspect_crontab_user) !== '' && ! $crontabUserChoices->contains($inspect_crontab_user))
                        <option value="{{ $inspect_crontab_user }}">{{ $inspect_crontab_user }}</option>
                    @endif
                    @foreach ($crontabInspectUserChoices as $u)
                        <option value="{{ $u }}">{{ $u }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('inspect_crontab_user')" class="mt-1" />
            </div>
            <button
                type="button"
                wire:click="loadInspectCrontab"
                wire:loading.attr="disabled"
                wire:target="loadInspectCrontab"
                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="loadInspectCrontab" class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrow-path class="h-4 w-4" />
                    {{ __('Reload') }}
                </span>
                <span wire:loading wire:target="loadInspectCrontab" class="inline-flex items-center gap-2">
                    <x-spinner variant="forest" />
                    {{ __('Loading…') }}
                </span>
            </button>
        </div>
        @if ($inspect_crontab_exit_code !== null)
            <p class="text-xs text-brand-moss">
                {{ __('Exit code: :code', ['code' => $inspect_crontab_exit_code]) }}
            </p>
        @endif
        @if ($inspect_crontab_body === null)
            {{-- Auto-load the crontab on first view (the default user is set in mount). --}}
            @if (trim($inspect_crontab_user) !== '')
                <div wire:init="loadInspectCrontab" class="flex items-center justify-center gap-2 rounded-xl border border-brand-ink/10 bg-zinc-950 px-6 py-12 text-sm text-slate-300">
                    <x-spinner variant="slate" size="sm" />
                    {{ __('Reading crontab…') }}
                </div>
            @else
                <div class="rounded-xl border border-brand-ink/10 bg-zinc-950 px-6 py-12 text-center text-sm text-slate-400">
                    {{ __('Choose a Linux user, then click Reload.') }}
                </div>
            @endif
        @else
            <div class="max-h-[min(55vh,28rem)] overflow-auto rounded-xl border border-brand-ink/10 bg-zinc-950">
                <pre class="whitespace-pre-wrap break-words p-4 font-mono text-xs leading-relaxed text-zinc-100">{{ $inspect_crontab_body !== '' ? $inspect_crontab_body : __('(empty crontab)') }}</pre>
            </div>
        @endif
    </div>
</div>
