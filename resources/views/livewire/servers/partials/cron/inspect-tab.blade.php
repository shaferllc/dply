<div class="{{ $card }}">
    <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-6 py-5 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <span class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-ink/5 text-brand-ink ring-1 ring-brand-ink/10">
                <x-heroicon-o-command-line class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Inspect crontab') }}</h2>
                <p class="mt-0.5 text-sm text-brand-moss leading-relaxed">
                    {{ __('Read-only: shows the real crontab file for that Linux user. Dply uses the SSH login user for “crontab -l”; other users need “sudo crontab -u … -l” (passwordless sudo).') }}
                </p>
            </div>
        </div>
    </div>
    <div class="space-y-4 p-6 sm:p-8">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <div class="min-w-0 flex-1">
                <x-input-label for="inspect_crontab_user" value="{{ __('Linux user') }}" />
                <input
                    id="inspect_crontab_user"
                    type="text"
                    wire:model="inspect_crontab_user"
                    autocomplete="off"
                    list="crontab-user-suggestions"
                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 font-mono text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
                    placeholder="{{ __('e.g. deploy, root') }}"
                />
                <datalist id="crontab-user-suggestions">
                    @foreach ($crontabInspectUserChoices as $u)
                        <option value="{{ $u }}"></option>
                    @endforeach
                </datalist>
                <x-input-error :messages="$errors->get('inspect_crontab_user')" class="mt-1" />
            </div>
            <button
                type="button"
                wire:click="loadInspectCrontab"
                wire:loading.attr="disabled"
                class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="loadInspectCrontab">{{ __('Load crontab') }}</span>
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
        <div class="max-h-[min(55vh,28rem)] overflow-auto rounded-xl border border-brand-ink/10 bg-zinc-950">
            <pre class="whitespace-pre-wrap break-words p-4 font-mono text-xs leading-relaxed text-zinc-100">@if ($inspect_crontab_body !== null){{ $inspect_crontab_body }}@else{{ __('Choose a user and click “Load crontab”.') }}@endif</pre>
        </div>
    </div>
</div>
