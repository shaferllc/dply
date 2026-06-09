@php
    $builder = app(\App\Services\Sites\SiteSystemdUnitBuilder::class);
    $workerProcesses = $site->processes->where('type', '!=', 'web');
    $runtimeUrl = route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'runtime']);
    $daemonsUrl = route('sites.daemons', ['server' => $server, 'site' => $site]);
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-cpu-chip class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Systemd') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Managed units') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Workers and schedulers run as separate systemd units. PHP/Laravel queue workers use Workers (Supervisor) instead.') }}</p>
            </div>
        </div>
        <button
            type="button"
            wire:click="syncSystemdUnits"
            wire:loading.attr="disabled"
            wire:target="syncSystemdUnits"
            class="shrink-0 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 disabled:opacity-50"
        >
            <span wire:loading.remove wire:target="syncSystemdUnits">{{ __('Sync to server') }}</span>
            <span wire:loading wire:target="syncSystemdUnits">{{ __('Syncing…') }}</span>
        </button>
    </div>
</section>

<x-server-workspace-tablist :aria-label="__('Services sections')" class="mt-6">
    <x-server-workspace-tab
        wire:click="setServicesWorkspaceTab('units')"
        :active="$services_workspace_tab === 'units'"
        icon="heroicon-o-cpu-chip"
    >{{ __('Units') }}</x-server-workspace-tab>
    <x-server-workspace-tab
        wire:click="setServicesWorkspaceTab('preview')"
        :active="$services_workspace_tab === 'preview'"
        icon="heroicon-o-document-text"
    >{{ __('Preview') }}</x-server-workspace-tab>
</x-server-workspace-tablist>

@if ($services_workspace_tab === 'units')
    {{-- Web unit (read-only) --}}
    @if (trim((string) $site->start_command) !== '')
        <section class="mt-6 dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('web') }}</span>
                        <h3 class="mt-1 font-mono text-sm font-semibold text-brand-ink">{{ $webUnitName }}</h3>
                        <p class="mt-1 break-all font-mono text-xs text-brand-moss">{{ $site->start_command }}</p>
                    </div>
                    <a href="{{ $runtimeUrl }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Edit on Runtime') }} →</a>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 px-6 py-4 sm:px-7">
                <button type="button" wire:click="previewUnit('web')" class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Preview unit file') }}</button>
            </div>
        </section>
    @endif

    {{-- Worker / scheduler units --}}
    <section class="mt-6 dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Worker & scheduler units') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ trans_choice('{0} No units yet|[1] 1 unit|[2,*] :count units', $workerProcesses->count(), ['count' => $workerProcesses->count()]) }}</p>
        </div>

        <div class="space-y-4 px-6 py-6 sm:px-7">
            @if ($workerProcesses->isNotEmpty())
                <ul class="space-y-2">
                    @foreach ($workerProcesses as $process)
                        @php $unitName = $builder->processUnitName($site, $process); @endphp
                        <li class="flex flex-wrap items-start justify-between gap-3 rounded-xl border border-brand-ink/10 bg-white p-3">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ $process->type }}</span>
                                    <span class="font-mono text-sm font-semibold text-brand-ink">{{ $unitName }}</span>
                                    @if (! $process->is_active)
                                        <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-900">{{ __('inactive') }}</span>
                                    @endif
                                </div>
                                <p class="mt-1 break-all font-mono text-xs text-brand-moss">{{ $process->command }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <button type="button" wire:click="previewUnit('{{ $process->id }}')" class="font-medium text-brand-forest hover:underline">{{ __('Preview') }}</button>
                                <button type="button" wire:click="restartSiteProcess('{{ $process->id }}')" wire:loading.attr="disabled" wire:target="restartSiteProcess" class="font-medium text-sky-700 hover:text-sky-800">{{ __('Restart') }}</button>
                                <button type="button" wire:click="toggleSiteProcessActive('{{ $process->id }}')" class="font-medium text-brand-moss hover:text-brand-ink">
                                    {{ $process->is_active ? __('Deactivate') : __('Activate') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('removeSiteProcess', ['{{ $process->id }}'], @js(__('Remove unit')), @js(__('Remove :name? Its systemd unit will be torn down on the server.', ['name' => $process->name])), @js(__('Remove')), true)"
                                    class="font-medium text-rose-700 hover:text-rose-800"
                                >{{ __('Remove') }}</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 p-4 text-sm text-brand-moss">
                    <p>{{ __('No worker or scheduler units yet. Add one below or use a preset.') }}</p>
                </div>
            @endif

            @if (is_array($systemdPresets) && $systemdPresets !== [])
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Presets') }}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($systemdPresets as $presetKey => $preset)
                            <button
                                type="button"
                                wire:click="applySystemdPreset('{{ $presetKey }}')"
                                class="rounded-lg border border-brand-ink/10 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40"
                            >{{ $preset['label'] ?? $presetKey }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/10 p-4">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Add unit') }}</p>
                <div class="mt-3 grid gap-2 sm:grid-cols-[110px,200px,1fr,auto] sm:items-end">
                    <div>
                        <label for="svc_process_type" class="block text-[11px] font-medium text-brand-moss">{{ __('Type') }}</label>
                        <select id="svc_process_type" wire:model="new_site_process_type" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm">
                            <option value="worker">{{ __('worker') }}</option>
                            <option value="scheduler">{{ __('scheduler') }}</option>
                            <option value="custom">{{ __('custom') }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="svc_process_name" class="block text-[11px] font-medium text-brand-moss">{{ __('Name') }}</label>
                        <input type="text" id="svc_process_name" wire:model="new_site_process_name" placeholder="sidekiq" class="mt-1 block w-full rounded-lg border-brand-ink/15 font-mono text-sm shadow-sm" />
                        <x-input-error :messages="$errors->get('new_site_process_name')" class="mt-1" />
                    </div>
                    <div>
                        <label for="svc_process_command" class="block text-[11px] font-medium text-brand-moss">{{ __('Command') }}</label>
                        <input type="text" id="svc_process_command" wire:model="new_site_process_command" placeholder="bundle exec sidekiq -C config/sidekiq.yml" class="mt-1 block w-full rounded-lg border-brand-ink/15 font-mono text-sm shadow-sm" />
                        <x-input-error :messages="$errors->get('new_site_process_command')" class="mt-1" />
                    </div>
                    <x-primary-button type="button" wire:click="addSiteProcess">{{ __('Add') }}</x-primary-button>
                </div>
            </div>

            <p class="text-xs text-brand-moss">
                {{ __('Laravel Horizon, queue:work, and most Rails workers run under') }}
                <a href="{{ $daemonsUrl }}" wire:navigate class="font-semibold text-brand-forest hover:underline">{{ __('Workers') }}</a>
                {{ __('(Supervisor), not systemd.') }}
            </p>
        </div>
    </section>
@else
    <section class="mt-6 dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Unit file preview') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Generated locally — sync writes these to /etc/systemd/system/ on the server.') }}</p>
        </div>
        <div class="space-y-4 px-6 py-6 sm:px-7">
            @if ($unit_preview_body !== '')
                <pre class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink p-4 font-mono text-xs leading-relaxed text-brand-cream">{{ $unit_preview_body }}</pre>
            @elseif ($unitPreviews !== [])
                @foreach ($unitPreviews as $filename => $body)
                    <div>
                        <p class="mb-2 font-mono text-xs font-semibold text-brand-moss">{{ $filename }}</p>
                        <pre class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink p-4 font-mono text-xs leading-relaxed text-brand-cream">{{ $body }}</pre>
                    </div>
                @endforeach
            @else
                <p class="text-sm text-brand-moss">{{ __('No units to preview. Set a start command on Runtime or add a worker unit.') }}</p>
            @endif
        </div>
    </section>
@endif
