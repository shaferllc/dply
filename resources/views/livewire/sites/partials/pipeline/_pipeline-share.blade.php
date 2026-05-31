@php
    $bashFull = $pipelineBashFull ?? '';
    $bashCommands = $pipelineBashCommands ?? '';
@endphp

<details class="rounded-2xl border border-brand-ink/10 bg-white">
    <summary class="cursor-pointer list-none px-4 py-3 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist marker:content-none sm:px-5">
        <span class="inline-flex items-center gap-1.5">
            <x-heroicon-m-chevron-right class="h-3.5 w-3.5 transition [[open]_&]:rotate-90" />
            {{ __('Share pipeline') }}
        </span>
    </summary>
    <div class="space-y-4 border-t border-brand-ink/10 px-4 py-4 sm:px-5">
        <p class="text-sm text-brand-moss">
            {{ __('Export for backup or docs, or import a .dply-pipeline.json file. Bash exports are reference only—Dply still runs the visual pipeline on deploy.') }}
        </p>

        <div class="flex flex-wrap gap-2">
            <button
                type="button"
                x-data="{ copied: false }"
                x-on:click="navigator.clipboard.writeText(@js($bashFull)).then(() => { copied = true; setTimeout(() => copied = false, 2000); })"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/50"
            >
                <x-heroicon-m-clipboard class="h-3.5 w-3.5" />
                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy full script'))"></span>
            </button>
            <button
                type="button"
                x-data="{ copied: false }"
                x-on:click="navigator.clipboard.writeText(@js($bashCommands)).then(() => { copied = true; setTimeout(() => copied = false, 2000); })"
                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/50"
            >
                <x-heroicon-m-clipboard-document-list class="h-3.5 w-3.5" />
                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy commands only'))"></span>
            </button>
            <x-secondary-button type="button" wire:click="downloadPipelineBashFull">
                {{ __('Download full .sh') }}
            </x-secondary-button>
            <x-secondary-button type="button" wire:click="downloadPipelineBashCommands">
                {{ __('Download commands .sh') }}
            </x-secondary-button>
            <x-secondary-button type="button" wire:click="downloadPipelineJson">
                {{ __('Download JSON') }}
            </x-secondary-button>
        </div>

        <div class="rounded-xl border border-dashed border-brand-sage/40 bg-brand-sage/5 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('Import pipeline JSON') }}</p>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Upload a file exported from this or another Dply site.') }}</p>
            <div class="mt-3">
                <input
                    type="file"
                    wire:model="pipeline_import_file"
                    accept=".json,application/json"
                    class="block w-full text-sm text-brand-moss file:mr-3 file:rounded-lg file:border-0 file:bg-brand-ink file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-brand-cream"
                />
                <div wire:loading wire:target="pipeline_import_file" class="mt-2 text-xs text-brand-moss">{{ __('Reading file…') }}</div>
                <x-input-error :messages="$errors->get('pipeline_import_file')" class="mt-1" />
            </div>
        </div>
    </div>
</details>
