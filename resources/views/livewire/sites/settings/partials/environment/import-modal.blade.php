    {{-- Import modal — KEY-AWARE and key-agnostic. env_import_key=null → seed the
         whole .env (workers verbatim, others sanitized). env_import_key=KEY → the
         universal per-variable import: any key, from any site that has it. No
         hard-coded key logic anywhere. --}}
    <x-modal name="env-import-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="px-6 py-5">
            @if ($env_import_key)
                @php $keySources = $this->envKeySources($env_import_key); @endphp
                <h3 class="font-mono text-base font-semibold text-brand-ink">{{ __('Import :key', ['key' => $env_import_key]) }}</h3>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Pick a site or worker that has this value set.') }}</p>
                <div class="mt-4 divide-y divide-brand-ink/5">
                    @forelse ($keySources as $s)
                        <div class="flex items-center justify-between gap-2 py-2">
                            <span class="min-w-0 truncate text-sm text-brand-ink">{{ $s['label'] }}<span class="text-brand-mist">{{ $s['server'] ? ' · '.$s['server'] : '' }}</span> <span class="font-mono text-[10px] text-brand-mist">{{ $s['masked'] }}</span></span>
                            <button type="button" wire:click="importEnvKeyFromSite(@js($env_import_key), '{{ $s['id'] }}')" x-on:click="$dispatch('close')" class="shrink-0 rounded-md bg-brand-ink px-2.5 py-1 text-[11px] font-semibold text-brand-cream hover:bg-brand-forest">{{ __('Use') }}</button>
                        </div>
                    @empty
                        <p class="py-3 text-sm text-brand-moss">{{ __('No other site has :key set.', ['key' => $env_import_key]) }}</p>
                    @endforelse
                </div>
            @else
                @php $importGroups = $this->envImportCandidates(); @endphp
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Import .env from another site') }}</h3>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Workers copy verbatim (same app — keeps APP_KEY & backend). Other sites import sanitized (secrets blanked, APP_KEY regenerated).') }}</p>
                <div class="mt-4 divide-y divide-brand-ink/5">
                    @if (! empty($importGroups['workers']))
                        <div class="py-3">
                            <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-brand-sage">{{ __('Pool workers — same app') }}</p>
                            @foreach ($importGroups['workers'] as $c)
                                <div class="flex items-center justify-between gap-2 py-1.5">
                                    <span class="text-sm text-brand-ink">{{ $c['label'] }} <span class="text-xs text-brand-mist">{{ $c['server'] ? '· '.$c['server'] : '' }}</span></span>
                                    <button type="button" wire:click="importEnvFromSite('{{ $c['id'] }}', true)" x-on:click="$dispatch('close')" class="rounded-md bg-brand-ink px-2.5 py-1 text-[11px] font-semibold text-brand-cream hover:bg-brand-forest">{{ __('Import verbatim') }}</button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                    @foreach (['same_repo' => __('Same repository'), 'org' => __('Other sites')] as $group => $heading)
                        @php $rows = collect($importGroups[$group] ?? [])->reject(fn ($c) => collect($importGroups['workers'])->pluck('id')->contains($c['id']))->values(); @endphp
                        @if ($rows->isNotEmpty())
                            <div class="py-3">
                                <p class="mb-2 text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ $heading }}</p>
                                @foreach ($rows as $c)
                                    <div class="flex items-center justify-between gap-2 py-1.5">
                                        <span class="text-sm text-brand-ink">{{ $c['label'] }} <span class="text-xs text-brand-mist">{{ $c['server'] ? '· '.$c['server'] : '' }}</span></span>
                                        <button type="button" wire:click="importEnvFromSite('{{ $c['id'] }}', false)" x-on:click="$dispatch('close')" class="rounded-md border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Import sanitized') }}</button>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @endforeach
                    @if (empty($importGroups['workers']) && empty($importGroups['same_repo']) && empty($importGroups['org']))
                        <p class="py-4 text-sm text-brand-moss">{{ __('No other sites with a saved .env yet. Paste your own instead.') }}</p>
                    @endif
                </div>
            @endif
            <div class="mt-4 flex items-center justify-between border-t border-brand-ink/10 pt-3">
                <button type="button" x-on:click="$dispatch('close'); $dispatch('open-modal', 'paste-env-modal')" class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Paste / upload a .env instead →') }}</button>
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
            </div>
        </div>
    </x-modal>
