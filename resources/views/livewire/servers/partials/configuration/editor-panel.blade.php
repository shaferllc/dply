<div class="min-w-0">
    @if ($config_selected_path === null)
        <div class="rounded-xl border border-dashed border-brand-ink/15 bg-white px-6 py-12 text-center text-sm text-brand-moss">
            <x-heroicon-o-document-text class="mx-auto h-5 w-5 text-brand-mist" />
            <p class="mt-2">{{ __('Select a config file to start editing.') }}</p>
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <p class="break-all font-mono text-xs text-brand-moss">{{ $config_selected_path }}</p>
                @if ($pending_load_console_id !== null)
                    <p class="mt-1 inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
                        <x-spinner variant="forest" class="h-3.5 w-3.5" />
                        {{ __('Loading file from server…') }}
                    </p>
                @endif
                @if ($config_truncated_on_load)
                    <p class="mt-1 inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900 ring-1 ring-amber-200">
                        <x-heroicon-o-exclamation-triangle class="h-3 w-3" />
                        {{ __('Truncated on load — saving is disabled') }}
                    </p>
                @endif
            </div>
            <div class="flex flex-wrap gap-1.5">
                <button
                    type="button"
                    wire:click="loadConfigFile(@js($config_selected_path))"
                    wire:loading.attr="disabled"
                    wire:target="loadConfigFile"
                    @disabled($pending_load_console_id !== null)
                    class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                >
                    <x-heroicon-o-arrow-path class="h-3 w-3" />
                    {{ __('Reload') }}
                </button>
                @if (! $isDeployer)
                    <button
                        type="button"
                        wire:click="validateConfigBuffer"
                        wire:loading.attr="disabled"
                        wire:target="validateConfigBuffer"
                        @disabled($config_truncated_on_load || $pending_load_console_id !== null)
                        class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                    >
                        <x-heroicon-o-shield-check class="h-3 w-3" />
                        {{ __('Validate') }}
                    </button>
                    <button
                        type="button"
                        wire:click="saveConfigFile"
                        wire:loading.attr="disabled"
                        wire:target="saveConfigFile,confirmConfigSave"
                        @disabled($config_truncated_on_load || $pending_load_console_id !== null)
                        class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-forest bg-brand-forest px-2.5 py-1 text-[11px] font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:opacity-50"
                    >
                        <x-heroicon-o-cloud-arrow-up class="h-3 w-3" />
                        {{ __('Save') }}
                    </button>
                @else
                    <span class="inline-flex items-center rounded-md bg-brand-sand/50 px-2 py-1 text-[10px] font-medium text-brand-moss ring-1 ring-brand-ink/10">
                        {{ __('Read-only') }}
                    </span>
                @endif
            </div>
        </div>

        @if ($pending_load_console_id !== null)
            <div class="mt-2 flex min-h-[50vh] items-center justify-center rounded-lg border border-brand-ink/15 bg-brand-sand/20">
                <div class="flex items-center gap-2 text-sm text-brand-moss">
                    <x-spinner variant="forest" class="h-5 w-5" />
                    <span>{{ __('Fetching file contents…') }}</span>
                </div>
            </div>
        @else
        <div
            wire:key="config-editor-{{ md5($config_selected_path) }}"
            x-data="{
                editor: null,
                init() {
                    if (! window.dplyFileBrowserMountEditor) return;
                    this.editor = window.dplyFileBrowserMountEditor(this.$refs.editorMount, {
                        content: this.$wire.config_contents ?? '',
                        path: this.$wire.config_selected_path ?? '',
                        readOnly: @js($isDeployer),
                        completions: @js($configAutocomplete),
                        onChange: (val) => {
                            if (! @js($isDeployer)) {
                                this.$wire.set('config_contents', val, true);
                            }
                        },
                    });
                },
            }"
            x-init="init()"
            x-on:livewire:navigated.window="if (editor) { editor.destroy(); init(); }"
            x-on:livewire-update.window="if (editor) editor.setContent($wire.config_contents ?? '')"
            class="mt-2 overflow-hidden rounded-lg border border-brand-ink/15 bg-brand-ink/95"
        >
            <div x-ref="editorMount" class="min-h-[50vh] max-h-[65vh] overflow-auto text-xs"></div>
        </div>
        @endif

        @include('livewire.servers.partials.configuration.revisions-panel')

        @if ($config_validate_output !== null)
            <div @class([
                'mt-3 rounded-xl border px-3 py-2 text-xs',
                'border-emerald-200 bg-emerald-50/70 text-emerald-900' => $config_validate_ok,
                'border-rose-200 bg-rose-50/70 text-rose-900' => ! $config_validate_ok,
            ])>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em]">
                    {{ $config_validate_ok ? __('Validation passed') : __('Validation reported problems') }}
                </p>
                <pre class="mt-1 max-h-40 overflow-auto whitespace-pre-wrap break-all font-mono text-[11px]">{{ $config_validate_output }}</pre>
            </div>
        @endif

        @if (! $isDeployer && ! empty($config_backups))
            <div class="mt-3 rounded-xl border border-brand-ink/10 bg-white">
                <div class="flex items-center justify-between border-b border-brand-ink/10 px-3 py-2">
                    <span class="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                        <x-heroicon-o-clock class="h-3 w-3" />
                        {{ __('Remote backups') }}
                    </span>
                    <span class="text-[10px] text-brand-mist">{{ __(':n kept — newest first', ['n' => count($config_backups)]) }}</span>
                </div>
                <ul class="max-h-48 divide-y divide-brand-ink/5 overflow-auto text-xs">
                    @foreach ($config_backups as $b)
                        <li class="flex items-center justify-between gap-3 px-3 py-1.5">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-[11px] text-brand-moss">{{ basename($b['path']) }}</p>
                                <p class="text-[10px] text-brand-mist">{{ \Illuminate\Support\Carbon::createFromTimestamp($b['mtime'])->diffForHumans() }} — {{ number_format($b['size']) }} bytes</p>
                            </div>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('restoreConfigBackup', [@js($b['path'])], @js(__('Restore backup?')), @js(__('Overwrite the live file with this backup? A snapshot of the current contents is taken first.')), @js(__('Restore')), true)"
                                class="shrink-0 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-medium text-brand-ink hover:bg-brand-sand/40"
                            >
                                {{ __('Restore') }}
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    @endif
</div>
