            {{-- =============================================================
                 CONFIG — file picker (left) + editor (right) with validate /
                 save / restore. Save is atomic on the server side (snapshot
                 to `_dply_backups/`, then `install -m 0644`), so a bad save
                 can always be undone by restoring the most recent backup.
                 ============================================================= --}}
            @if ($engine_subtab === 'config' && $isActive && $engineHasFullControls($key))
                <div class="{{ $card }} p-6 sm:p-8">
                    <div>
                        <h3 class="text-base font-semibold text-brand-ink">{{ __(':engine config editor', ['engine' => $info['label']]) }}</h3>
                        <p class="mt-1 max-w-3xl text-sm text-brand-moss">{{ __('Edit → Validate (dry-run) → Save. Save snapshots the live file to _dply_backups/, atomically installs, re-validates, and auto-restores the snapshot if validation rejects the new file. Every save is kept as a revision.') }}</p>
                    </div>

                    @if (! $opsReady || $isDeployer)
                        <p class="mt-4 text-sm text-brand-moss">{{ __('Editing config requires ready ops access and a non-deployer role.') }}</p>
                    @else
                        <div class="mt-5 grid gap-5 lg:grid-cols-[260px_minmax(0,1fr)]">
                            {{-- File picker --}}
                            <div class="rounded-xl border border-brand-ink/10 bg-white">
                                <div class="border-b border-brand-ink/10 px-3 py-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Files') }}</div>
                                @if (empty($webserverConfigFiles))
                                    <p class="px-3 py-3 text-xs text-brand-moss">{{ __('No config files discovered. Confirm the server is reachable.') }}</p>
                                @else
                                    <ul class="max-h-[55vh] divide-y divide-brand-ink/5 overflow-auto text-sm">
                                        @foreach ($webserverConfigFiles as $f)
                                            @php $isSel = $config_selected_path === $f['path']; @endphp
                                            <li>
                                                @php
                                                    // `$pending_load_path` stays set on this row from the moment the
                                                    // load is dispatched until pickupQueuedConfigLoad() drops the
                                                    // result into the buffer — covering the queued→running window.
                                                    // The wire:loading swap covers the instant click→Livewire ack
                                                    // gap so the spinner appears before the row's `wire:loading.attr`
                                                    // even fires. `data-skip-busy` opts out of the global busy class
                                                    // that hides all child elements (would mask our spinner).
                                                    $isLoading = $pending_load_path === $f['path'];
                                                @endphp
                                                <button
                                                    type="button"
                                                    wire:click="loadWebserverConfig(@js($f['path']))"
                                                    wire:target="loadWebserverConfig(@js($f['path']))"
                                                    wire:loading.attr="disabled"
                                                    data-skip-busy="1"
                                                    @class([
                                                        'flex w-full items-start gap-2 px-3 py-2 text-left transition-colors hover:bg-brand-sand/40',
                                                        'bg-brand-sand/50' => $isSel && ! $isLoading,
                                                        'bg-brand-sand/60 cursor-progress' => $isLoading,
                                                    ])
                                                >
                                                    <span class="mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center">
                                                        @if ($isLoading)
                                                            <x-spinner variant="forest" class="h-3.5 w-3.5" />
                                                        @else
                                                            <span wire:loading.remove wire:target="loadWebserverConfig(@js($f['path']))" class="inline-flex">
                                                                <x-heroicon-o-document class="h-4 w-4 text-brand-moss" />
                                                            </span>
                                                            <span wire:loading wire:target="loadWebserverConfig(@js($f['path']))" class="inline-flex">
                                                                <x-spinner variant="forest" class="h-3.5 w-3.5" />
                                                            </span>
                                                        @endif
                                                    </span>
                                                    <span class="min-w-0 flex-1">
                                                        <span class="block truncate font-medium text-brand-ink">{{ $f['label'] }}</span>
                                                        <span class="block truncate font-mono text-[10px] text-brand-mist">{{ $f['path'] }}</span>
                                                        @php $fileDescription = app(\App\Services\Servers\WebserverConfigDocLinks::class)->describe($key, $f['path']); @endphp
                                                        @if ($fileDescription)
                                                            <span class="mt-1 line-clamp-2 block text-[10px] leading-snug text-brand-moss">{{ $fileDescription }}</span>
                                                        @endif
                                                    </span>
                                                    <span class="shrink-0 font-mono text-[10px] text-brand-mist">
                                                        @if ($isLoading)
                                                            {{ __('loading…') }}
                                                        @else
                                                            {{ number_format($f['size']) }}b
                                                        @endif
                                                    </span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>

                            {{-- Editor --}}
                            <div class="min-w-0">
                                @if ($config_selected_path === null)
                                    <div class="rounded-xl border border-dashed border-brand-ink/15 bg-white px-6 py-12 text-center text-sm text-brand-moss">
                                        <x-heroicon-o-arrow-left class="mx-auto h-5 w-5 text-brand-mist" />
                                        <p class="mt-2">{{ __('Pick a file on the left to start editing.') }}</p>
                                    </div>
                                @else
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <div class="min-w-0">
                                            <p class="break-all font-mono text-xs text-brand-moss">{{ $config_selected_path }}</p>
                                            @php
                                                $docResolver = app(\App\Services\Servers\WebserverConfigDocLinks::class);
                                                $docLink = $docResolver->resolve($key, $config_selected_path);
                                                $selectedDescription = $docResolver->describe($key, $config_selected_path);
                                            @endphp
                                            @if ($selectedDescription)
                                                <p class="mt-1 max-w-prose text-[12px] leading-snug text-brand-moss">{{ $selectedDescription }}</p>
                                            @endif
                                            @if ($docLink)
                                                <a
                                                    href="{{ $docLink['url'] }}"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="mt-1 inline-flex items-center gap-1 text-[11px] font-medium text-brand-forest hover:underline"
                                                    title="{{ $docLink['label'] }}"
                                                >
                                                    <x-heroicon-o-book-open class="h-3 w-3" />
                                                    {{ __('Docs') }}
                                                    <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3 opacity-70" />
                                                </a>
                                            @endif
                                            @if ($config_truncated_on_load)
                                                <p class="mt-1 inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-900 ring-1 ring-amber-200">
                                                    <x-heroicon-o-exclamation-triangle class="h-3 w-3" />
                                                    {{ __('Truncated on load — saving is disabled') }}
                                                </p>
                                            @endif
                                        </div>
                                        <div class="flex flex-wrap gap-1.5">
                                            <button type="button" wire:click="loadWebserverConfig(@js($config_selected_path))" class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40">
                                                <x-heroicon-o-arrow-path class="h-3 w-3" />
                                                {{ __('Reload') }}
                                            </button>
                                            @php
                                                // Reset-to-default only makes sense for files dply owns a builder for.
                                                // For OLS that's httpd_config.conf and per-site vhconf.conf — gate on
                                                // those paths so the button doesn't tease engines we haven't wired up.
                                                $resetable = $key === 'openlitespeed'
                                                    && (
                                                        $config_selected_path === '/usr/local/lsws/conf/httpd_config.conf'
                                                        || (is_string($config_selected_path) && preg_match('#^/usr/local/lsws/conf/vhosts/[^/]+/vhconf\.conf$#', $config_selected_path) === 1)
                                                    );
                                            @endphp
                                            @if ($resetable)
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('resetWebserverConfigToDefault', [], @js(__('Reset to dply default?')), @js(__('Replace the editor buffer with the canonical content dply\'s provisioner would emit. Nothing is written until you click Save. Your current buffer is lost.')), @js(__('Reset')), false)"
                                                    class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                >
                                                    <x-heroicon-o-arrow-uturn-down class="h-3 w-3" />
                                                    {{ __('Reset to default') }}
                                                </button>
                                            @endif
                                            <button
                                                type="button"
                                                wire:click="validateWebserverConfigBuffer"
                                                wire:loading.attr="disabled"
                                                wire:target="validateWebserverConfigBuffer"
                                                @disabled($config_truncated_on_load)
                                                class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-medium text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                                            >
                                                <span wire:loading.remove wire:target="validateWebserverConfigBuffer" class="inline-flex">
                                                    <x-heroicon-o-shield-check class="h-3 w-3" />
                                                </span>
                                                <span wire:loading wire:target="validateWebserverConfigBuffer" class="inline-flex">
                                                    <x-spinner class="h-3 w-3" />
                                                </span>
                                                {{ __('Validate') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="saveWebserverConfig"
                                                wire:loading.attr="disabled"
                                                wire:target="saveWebserverConfig"
                                                @disabled($config_truncated_on_load)
                                                class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-forest bg-brand-forest px-2.5 py-1 text-[11px] font-semibold text-brand-cream hover:bg-brand-forest/90 disabled:opacity-50"
                                            >
                                                <span wire:loading.remove wire:target="saveWebserverConfig" class="inline-flex">
                                                    <x-heroicon-o-cloud-arrow-up class="h-3 w-3" />
                                                </span>
                                                <span wire:loading wire:target="saveWebserverConfig" class="inline-flex">
                                                    <x-spinner variant="cream" class="h-3 w-3" />
                                                </span>
                                                {{ __('Save') }}
                                            </button>
                                        </div>
                                    </div>

                                    {{-- wire:key tied to the path forces Livewire to recreate this
                                         textarea when a new file is loaded. Without it, the morph
                                         step preserves the existing (empty) value attribute when
                                         the @else branch re-mounts and the buffer never paints
                                         the freshly-loaded contents. --}}
                                    <textarea
                                        wire:model.live.debounce.500ms="config_contents"
                                        wire:key="config-textarea-{{ $config_selected_path }}"
                                        rows="22"
                                        spellcheck="false"
                                        class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-brand-ink/95 p-3 font-mono text-xs leading-relaxed text-emerald-100 shadow-inner focus:border-brand-forest focus:ring-brand-sage/30"
                                    >{{ $config_contents }}</textarea>

                                    {{-- Validate output --}}
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

                                    {{-- Revisions --}}
                                    @if (! empty($config_backups))
                                        <div class="mt-3 rounded-xl border border-brand-ink/10 bg-white">
                                            <div class="flex items-center justify-between border-b border-brand-ink/10 px-3 py-2">
                                                <span class="inline-flex items-center gap-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">
                                                    <x-heroicon-o-clock class="h-3 w-3" />
                                                    {{ __('Revisions') }}
                                                </span>
                                                <span class="text-[10px] text-brand-mist">{{ __(':n kept — newest first; click Restore to roll back', ['n' => count($config_backups)]) }}</span>
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
                                                            wire:click="openConfirmActionModal('restoreWebserverConfigBackup', [@js($b['path'])], @js(__('Restore backup?')), @js(__('Overwrite the live file with this backup? A snapshot of the current contents is taken first.')), @js(__('Restore')), true)"
                                                            class="shrink-0 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-medium text-brand-ink hover:bg-brand-sand/40"
                                                        >
                                                            <x-heroicon-o-arrow-uturn-left class="inline h-3 w-3" />
                                                            {{ __('Restore') }}
                                                        </button>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endif

