<details class="rounded-2xl border border-brand-ink/10 bg-white">
    <summary class="cursor-pointer list-none px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist marker:content-none sm:px-5">
        <span class="inline-flex items-center gap-1.5">
            <x-heroicon-m-chevron-right class="h-3.5 w-3.5 transition [[open]_&]:rotate-90" />
            {{ __('Quick commands') }}
        </span>
    </summary>
    <div class="space-y-3 border-t border-brand-ink/10 px-4 py-4 sm:px-5">
        <p class="text-sm text-brand-moss">
            {{ __('One shell command per line. Lines are appended as custom steps on this pipeline (lines starting with # are ignored).') }}
        </p>
        <form wire:submit="appendQuickCommands" class="space-y-3">
            <textarea
                wire:model="quick_commands_text"
                rows="5"
                class="w-full rounded-lg border border-brand-ink/15 px-3 py-2 font-mono text-sm text-brand-ink"
                placeholder="{{ __("composer install\nnpm ci\nnpm run build") }}"
            ></textarea>
            <x-input-error :messages="$errors->get('quick_commands_text')" class="mt-1" />
            <div class="flex flex-wrap items-center gap-3">
                <label class="text-xs font-medium text-brand-moss" for="quick_commands_phase">{{ __('Phase') }}</label>
                <select
                    id="quick_commands_phase"
                    wire:model="quick_commands_phase"
                    class="rounded-lg border border-brand-ink/15 px-3 py-1.5 text-sm"
                >
                    <option value="build">{{ __('Build') }}</option>
                    <option value="release">{{ __('Release') }}</option>
                </select>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="appendQuickCommands">
                    <span wire:loading.remove wire:target="appendQuickCommands">{{ __('Add commands') }}</span>
                    <span wire:loading wire:target="appendQuickCommands">{{ __('Adding…') }}</span>
                </x-primary-button>
            </div>
        </form>
    </div>
</details>
