@php
    $importTargetSite = $contextSiteModel ?? $sitesForServer->firstWhere('id', $import_to_site_id);
    $canImport = $sitesForImport->isNotEmpty() && ($contextSiteModel !== null || $import_to_site_id !== '');
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-arrow-down-tray class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Reuse') }}</p>
            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Import from another site') }}</h3>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                @if ($contextSiteModel)
                    {{ __('Copy Supervisor programs from another site on this server into :site. Paths and system user are adjusted for this site.', ['site' => $contextSiteModel->name]) }}
                @else
                    {{ __('Copy Supervisor programs between sites on this server. Paths and system user are adjusted for the destination site.') }}
                @endif
            </p>
        </div>
    </div>

    <div class="space-y-5 p-6 sm:p-7">
        @if ($sitesForServer->count() < 2)
            <p class="text-sm text-brand-moss">{{ __('Add another site on this server to import programs from it.') }}</p>
        @else
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="import_from_site_id" value="{{ __('From site') }}" />
                    <select
                        id="import_from_site_id"
                        wire:model.live="import_from_site_id"
                        class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm"
                    >
                        <option value="">{{ __('Choose a site…') }}</option>
                        @foreach ($sitesForImport as $siteOption)
                            <option value="{{ $siteOption->id }}">{{ $siteOption->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('import_from_site_id')" class="mt-1" />
                </div>

                @unless ($contextSiteModel)
                    <div>
                        <x-input-label for="import_to_site_id" value="{{ __('To site') }}" />
                        <select
                            id="import_to_site_id"
                            wire:model.live="import_to_site_id"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2.5 text-sm"
                        >
                            <option value="">{{ __('Choose a site…') }}</option>
                            @foreach ($sitesForServer as $siteOption)
                                <option value="{{ $siteOption->id }}">{{ $siteOption->name }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('import_to_site_id')" class="mt-1" />
                    </div>
                @endunless
            </div>

            @if ($canImport && $import_from_site_id !== '' && $importSourcePrograms->isEmpty())
                <p class="text-sm text-brand-moss">{{ __('No programs are linked to the source site yet.') }}</p>
            @endif

            @if ($importSourcePrograms->isNotEmpty())
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">
                        {{ trans_choice(':count program on source site|:count programs on source site', $importSourcePrograms->count(), ['count' => $importSourcePrograms->count()]) }}
                    </p>
                    @if ($importSourcePrograms->count() > 1)
                        <button
                            type="button"
                            wire:click="importAllProgramsFromSite"
                            wire:loading.attr="disabled"
                            wire:target="importAllProgramsFromSite,importProgramFromSite"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-m-arrow-down-tray class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Import all') }}
                        </button>
                    @endif
                </div>

                <ul class="divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                    @foreach ($importSourcePrograms as $program)
                        <li class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between" wire:key="import-src-{{ $program->id }}">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-semibold text-brand-ink">{{ $program->slug }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">
                                    <span class="font-medium text-brand-ink/80">{{ $program->program_type }}</span>
                                    · {{ __('numprocs') }} {{ $program->numprocs }}
                                </p>
                                <p class="mt-1 break-all font-mono text-xs leading-relaxed text-brand-mist">{{ $program->command }}</p>
                            </div>
                            <button
                                type="button"
                                wire:click="importProgramFromSite('{{ $program->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="importProgramFromSite,importAllProgramsFromSite"
                                class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-ink disabled:cursor-not-allowed disabled:opacity-50 sm:self-center"
                            >
                                <span wire:loading.remove wire:target="importProgramFromSite,importAllProgramsFromSite">
                                    {{ __('Import') }}
                                </span>
                                <span wire:loading wire:target="importProgramFromSite,importAllProgramsFromSite" class="inline-flex items-center gap-1.5">
                                    <x-spinner variant="cream" size="sm" />
                                    {{ __('Importing…') }}
                                </span>
                            </button>
                        </li>
                    @endforeach
                </ul>

                @if ($importTargetSite)
                    <p class="text-xs leading-relaxed text-brand-moss">
                        {{ __('Imported programs are assigned to :site with updated directory paths. Sync Supervisor afterward to write configs on the server.', ['site' => $importTargetSite->name]) }}
                    </p>
                @endif
            @endif
        @endif
    </div>
</section>
