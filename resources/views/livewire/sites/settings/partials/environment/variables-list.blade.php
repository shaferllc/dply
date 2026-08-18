    {{-- The per-key list. Each row: key (font-mono) + masked value with toggle,
         inline edit, trash. "Discovered from server" badge fires when the cache
         came from a sync (origin === 'server') and the key isn't part of the
         workspace inherited set. --}}
    <div
        class="{{ $card }}"
        @if ($envSyncInFlight) wire:poll.3s @endif
    >
        {{-- Single merged header: identity + count/freshness on the left, every
             variables action on the right (Sync, Paste, View/edit all, Add). --}}
        {{-- Identity and actions share one line. They were stacked as two rows
             split by a border, which cost ~40px of header before a single
             variable appeared. --}}
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-2.5 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                <div class="flex min-w-0 flex-wrap items-center gap-2">
                    <x-heroicon-o-key class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    <h2 class="text-sm font-semibold text-brand-ink">{{ __('Environment variables') }}</h2>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-white px-2 py-0.5 text-2xs font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">
                        <span class="h-1.5 w-1.5 rounded-full bg-brand-forest" aria-hidden="true"></span>
                        {{ trans_choice('{0} no variables|{1} :count variable|[2,*] :count variables', $variableCount, ['count' => $variableCount]) }}
                    </span>
                    @if ($workspaceVariables->isNotEmpty())
                        <span class="inline-flex items-center gap-1 text-xs text-brand-mist"><x-heroicon-m-link class="h-3 w-3" />{{ trans_choice('{1} :count inherited|[2,*] :count inherited', $workspaceVariables->count(), ['count' => $workspaceVariables->count()]) }}</span>
                    @endif
                    @if ($freshnessLabel)
                        <span class="text-xs text-brand-mist">· {{ $freshnessLabel }}</span>
                    @endif
                </div>
            {{-- Action toolbar: create actions on the left, the primary CTA
                 anchored right, and the occasional server / bulk-edit tools
                 tucked into a "More" menu so the bar stays tidy as it grows. --}}
            <div class="flex flex-wrap items-center gap-1.5">
                {{-- Resource attach/configure lives on the Resources tab now. --}}

                @if (method_exists($this, 'testSiteLoads'))
                    {{-- End-to-end check: actually request the site and report
                         whether it loads, pulling the server error on failure. --}}
                    <button
                        type="button"
                        wire:click="testSiteLoads"
                        wire:loading.attr="disabled"
                        wire:target="testSiteLoads"
                        class="dply-btn dply-btn-sm border border-brand-forest/30 bg-brand-forest/5 text-brand-forest hover:bg-brand-forest/10"
                        title="{{ __('Request the live site and confirm it loads (HTTP check + server log on failure).') }}"
                    >
                        <x-heroicon-o-beaker class="h-3.5 w-3.5" wire:loading.remove wire:target="testSiteLoads" />
                        <span wire:loading wire:target="testSiteLoads" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                        <span wire:loading.remove wire:target="testSiteLoads">{{ __('Test site') }}</span>
                        <span wire:loading wire:target="testSiteLoads">{{ __('Testing…') }}</span>
                    </button>
                @endif

                {{-- Overflow: occasional server-sync + bulk-edit tools. --}}
                <div x-data="{ open: false }" class="relative z-30">
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        x-on:click.outside="open = false"
                        class="dply-btn dply-btn-sm dply-btn-outline"
                    >
                        <x-heroicon-m-ellipsis-horizontal class="h-3.5 w-3.5 text-brand-mist" />
                        {{ __('More') }}
                        <x-heroicon-m-chevron-down class="h-3 w-3 text-brand-mist" />
                    </button>
                    <div
                        x-show="open"
                        x-cloak
                        x-transition
                        class="absolute left-0 z-50 mt-1 w-60 overflow-hidden rounded-xl border border-brand-ink/10 bg-white py-1 shadow-lg"
                    >
                        @if ($supportsEnvPush && method_exists($this, 'pushEnvToServer'))
                            <button type="button" wire:click="pushEnvToServer" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40" title="{{ __('Write these variables (including connected resources) to the server\'s .env now.') }}">
                                <x-heroicon-o-arrow-up-tray class="h-4 w-4 text-brand-forest" /> {{ __('Push to server') }}
                            </button>
                        @endif
                        @if ($supportsEnvPush && method_exists($this, 'applyEnvToWorkers') && $this->hasWorkerReplicas())
                            <button type="button" wire:click="openConfirmActionModal('applyEnvToWorkers', [], @js(__('Sync these variables to workers?')), @js(__('Each worker-pool replica gets this site\'s variables, keeping its own queue, HORIZON_*, and worker-role keys. Replicas whose values already match are skipped; the rest are pushed and their worker units restarted.')), @js(__('Sync to workers')), false)" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40" title="{{ __('Propagate these variables to every worker replica cloned from this site.') }}">
                                <x-heroicon-o-arrows-right-left class="h-4 w-4 text-brand-forest" /> {{ __('Sync to workers') }}
                            </button>
                        @endif
                        @if ($supportsEnvPush)
                            <button type="button" wire:click="openConfirmActionModal('syncEnvFromServer', [], @js(__('Sync from server?')), @js(__('This replaces the cached variables with the live .env on the server. Any local edits here that haven\'t been pushed — and connection variables injected by attached resources (managed databases, caches) — will be overwritten with the server copy.')), @js(__('Overwrite with server copy')), true)" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-arrow-down-tray class="h-4 w-4 text-brand-moss" /> {{ __('Sync from server') }}
                            </button>
                        @endif
                        @if ($supportsEnvPush && method_exists($this, 'rescanEnvRequirements'))
                            {{-- Always available (not gated on the missing-vars banner) so a
                                 site whose env was never scanned can populate env_requirements
                                 from .env.example + code, then "Add missing variables". --}}
                            <button type="button" wire:click="rescanEnvRequirements" x-on:click="open = false" class="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-brand-sand/40" title="{{ __('Scan the deployed code (.env.example + env() usage) for required variables so missing ones can be imported.') }}">
                                <x-heroicon-o-magnifying-glass class="mt-0.5 h-4 w-4 shrink-0 text-brand-moss" />
                                <span>
                                    <span class="block text-xs font-semibold text-brand-ink">{{ __('Scan for required variables') }}</span>
                                    <span class="block text-2xs text-brand-mist">{{ $envScannedAt ? __('Last scanned :when', ['when' => \Illuminate\Support\Carbon::parse($envScannedAt)->diffForHumans()]) : __('Not scanned yet') }}</span>
                                </span>
                            </button>
                        @endif
                        <button type="button" wire:click="$set('env_import_key', null)" x-on:click="open = false; $dispatch('open-modal', 'env-import-modal')" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-down-on-square class="h-4 w-4 text-brand-moss" /> {{ __('Import from another site') }}
                        </button>
                        @if (method_exists($this, 'runRemediation'))
                            <div class="my-1 border-t border-brand-ink/10"></div>
                            <button type="button" wire:click="runRemediation('optimize_clear')" x-on:click="open = false" class="flex w-full items-start gap-2 px-3 py-2 text-left hover:bg-brand-sand/40">
                                <x-heroicon-o-sparkles class="mt-0.5 h-4 w-4 shrink-0 text-brand-moss" />
                                <span>
                                    <span class="block text-xs font-semibold text-brand-ink">{{ __('Clear all caches') }}</span>
                                    <span class="block text-2xs text-brand-mist">{{ __('Includes config (env), route, and view caches') }}</span>
                                </span>
                            </button>
                        @endif
                    </div>
                </div>

                @if ($envAdvanced)
                    <button
                        type="button"
                        wire:click="openEditAllEnv"
                        class="dply-btn dply-btn-sm dply-btn-outline"
                    >
                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                        {{ __('Edit all') }}
                    </button>
                @endif
                <button
                    type="button"
                    x-on:click="$dispatch('open-modal', 'add-env-modal')"
                    class="dply-btn dply-btn-sm dply-btn-primary"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Add variable') }}
                </button>
            </div>
            </div>
            <p class="mt-1 text-xs leading-relaxed text-brand-moss">
                @if ($supportsEnvPush)
                    {{ __('Key/value pairs written into the site\'s .env file. Edits push to the server automatically.') }}
                @else
                    {{ __('Key/value pairs injected into the runtime on the next deploy.') }}
                @endif
            </p>
        </div>

        @if ($variableCount > 0 && $envAdvanced)
            {{-- Search and the prefix filters share a line: the search field was
                 full-width on its own row above the chips for no reason. --}}
            <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-white px-5 py-1.5 sm:px-6">
                <div class="relative w-full sm:w-56">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-brand-mist" />
                    <input
                        type="search"
                        wire:model.live.debounce.200ms="env_search"
                        placeholder="{{ __('Search variables…') }}"
                        class="block w-full rounded-lg border border-brand-ink/15 bg-brand-cream/40 py-1 pl-8 pr-2 font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30"
                    />
                </div>
                @if (count($envGroups) > 1)
                    {{-- Auto-derived prefix groups (APP_, DB_, AWS_, …). Click to
                         filter the list to that group; combines with search. --}}
                    <div class="flex min-w-0 flex-wrap gap-1">
                        <button type="button" wire:click="$set('env_group', '')" @class([
                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold transition-colors',
                            'bg-brand-forest text-brand-cream' => $selectedEnvGroup === '',
                            'bg-brand-sand/40 text-brand-moss hover:bg-brand-sand/60' => $selectedEnvGroup !== '',
                        ])>
                            {{ __('All') }} <span class="opacity-60">{{ $variableCount }}</span>
                        </button>
                        @foreach ($envGroups as $g => $cnt)
                            <button type="button" wire:click="$set('env_group', @js($g))" @class([
                                'inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-mono text-xs font-semibold transition-colors',
                                'bg-brand-forest text-brand-cream' => $selectedEnvGroup === $g,
                                'bg-brand-sand/40 text-brand-moss hover:bg-brand-sand/60' => $selectedEnvGroup !== $g,
                            ])>
                                {{ $g }} <span class="opacity-60">{{ $cnt }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Connection variables provided by attached resource bindings. Shown
             inline (not in a separate card) so the .env story is in one place.
             Secret-looking values are masked server-side; Override loads the
             real value into the editor and writes a .env key that wins. --}}
        @php
            // .env keys that override a connected resource binding — merged into
            // the managed-groups section so each binding is one visual unit.
            $overrideGroups = [];
            foreach ($filteredEnvMap as $_oKey => $_oVal) {
                $_ob = $bindingProvidedKeys[$_oKey] ?? null;
                if ($_ob === null) { continue; }
                $_bid = $_ob['bindingId'];
                if (! isset($overrideGroups[$_bid])) {
                    $overrideGroups[$_bid] = ['type' => $_ob['type'], 'name' => $_ob['name'], 'bindingId' => $_bid, 'keys' => []];
                }
                $overrideGroups[$_bid]['keys'][$_oKey] = (string) $_oVal;
            }
            foreach ($overrideGroups as &$_og) { ksort($_og['keys']); }
            unset($_og);
            $overrideGroupedKeySet = [];
            foreach ($overrideGroups as $_og) {
                foreach (array_keys($_og['keys']) as $_ogk) {
                    $overrideGroupedKeySet[$_ogk] = true;
                }
            }
        @endphp

        {{-- Connection variables provided by attached resource bindings, grouped
             by the resource that supplies them. Each group header carries the
             resource identity + whole-binding actions (Update re-opens the
             picker to re-point/refresh; Detach removes it); the rows beneath are
             the individual variables, each overridable. User overrides for keys
             in that binding are shown as a sub-section within the same group. --}}
        @if ($bindingManagedGroups !== [] || $overrideGroups !== [])
            <div class="border-b border-brand-ink/10 bg-sky-50/20">
                <div class="flex items-center gap-2 px-5 py-1.5 sm:px-6">
                    <x-heroicon-o-link class="h-3.5 w-3.5 text-sky-700" aria-hidden="true" />
                    <p class="text-xs font-semibold uppercase tracking-[0.16em] text-sky-800">{{ __('Managed by connected resources') }}</p>
                    <span class="text-xs text-brand-moss">{{ __('injected at deploy · editable as an override') }}</span>
                </div>

                @foreach ($bindingManagedGroups as $gBindingId => $group)
                    @php
                        $gTypeLabel = $bindingTypeLabelsInline[$group['type']] ?? (string) str($group['type'])->title();
                        $gConn = is_array($group['connectivity'] ?? null) ? $group['connectivity'] : null;
                        $gManageable = in_array($group['type'], ['database', 'redis', 'queue', 'session', 'storage', 'mail'], true);
                        $gGroupOverrides = $overrideGroups[(string) $gBindingId] ?? null;
                        $gHasEditing = ($editing_env_key ?? null) !== null
                            && (array_key_exists((string) $editing_env_key, $group['vars'])
                                || ($gGroupOverrides && array_key_exists((string) $editing_env_key, $gGroupOverrides['keys'])));
                    @endphp
                    <div class="border-t border-sky-200/40" wire:key="managed-group-{{ md5($gBindingId) }}" x-data="{ expanded: @js($gHasEditing) }">
                        <div class="flex flex-wrap items-center justify-between gap-2 bg-sky-50/60 px-5 py-1.5 sm:px-6">
                            <button type="button" x-on:click="expanded = ! expanded" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform" x-bind:class="expanded && 'rotate-90'" />
                                <span class="text-sm font-semibold text-brand-ink">{{ $gTypeLabel }}</span>
                                @if ($group['name'])
                                    <span class="truncate font-mono text-xs text-brand-moss">· {{ $group['name'] }}</span>
                                @endif
                                <span class="shrink-0 rounded-full bg-white px-1.5 py-0.5 text-2xs font-semibold text-brand-moss ring-1 ring-inset ring-brand-ink/10">{{ trans_choice('{1} :count var|[2,*] :count vars', count($group['vars']), ['count' => count($group['vars'])]) }}</span>
                                @if ($gGroupOverrides)
                                    <span class="shrink-0 rounded-full bg-amber-50 px-1.5 py-0.5 text-2xs font-semibold text-amber-800 ring-1 ring-inset ring-amber-200/70">{{ trans_choice('{1} :count override|[2,*] :count overrides', count($gGroupOverrides['keys']), ['count' => count($gGroupOverrides['keys'])]) }}</span>
                                @endif
                                @if ($gConn !== null && ($gConn['ok'] ?? null) === true)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] text-emerald-800 ring-1 ring-inset ring-emerald-200/70"><x-heroicon-m-check class="h-3 w-3" />{{ __('Reachable') }}</span>
                                @elseif ($gConn !== null && ($gConn['ok'] ?? null) === false)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] text-rose-800 ring-1 ring-inset ring-rose-200/70" title="{{ $gConn['detail'] ?? '' }}"><x-heroicon-m-exclamation-triangle class="h-3 w-3" />{{ __('Unreachable') }}</span>
                                @endif
                            </button>
                            <div class="flex shrink-0 items-center gap-1.5">
                                @if (($gConn['ok'] ?? null) === false && method_exists($this, 'fixBindingConnectivity'))
                                    <button type="button" wire:click="startFixBinding(@js((string) $gBindingId))" x-on:click="$dispatch('open-modal', 'fix-binding-modal')" class="dply-btn dply-btn-xs border border-rose-200 bg-white text-rose-700 hover:bg-rose-50" title="{{ __('Fix the private-network connectivity for this resource.') }}">
                                        <x-heroicon-o-wrench-screwdriver class="h-3 w-3" />
                                        {{ __('Fix') }}
                                    </button>
                                @endif
                                @if (in_array($group['type'], ['database', 'redis'], true) && method_exists($this, 'verifyBinding'))
                                    <button type="button" wire:click="verifyBinding(@js((string) $gBindingId))" wire:loading.attr="disabled" wire:target="verifyBinding" class="dply-btn dply-btn-xs dply-btn-outline" title="{{ __('Probe the connection from the server now.') }}">
                                        <x-heroicon-o-signal class="h-3 w-3" />
                                        {{ __('Verify') }}
                                    </button>
                                @endif
                                @if ($group['type'] === 'mail' && method_exists($this, 'sendBindingTestEmail') && method_exists($this, 'seedQueuedConsoleAction'))
                                    {{-- Send a test email from the server using this binding's
                                         transport. Recipient defaults to the operator's email
                                         (left blank → the job uses it); editable in the popover. --}}
                                    <div class="relative" x-data="{ open: false }" wire:key="mailtest-{{ md5($gBindingId) }}">
                                        <button type="button" x-on:click="open = !open" class="dply-btn dply-btn-xs dply-btn-outline" title="{{ __('Send a test email from the server using this transport.') }}">
                                            <x-heroicon-o-paper-airplane class="h-3 w-3" />
                                            {{ __('Send test') }}
                                        </button>
                                        <div x-show="open" x-cloak x-on:click.outside="open = false" x-transition class="absolute right-0 z-20 mt-1 w-72 rounded-xl border border-brand-ink/10 bg-white p-3 shadow-lg">
                                            <x-input-label for="mailtest_to_{{ md5($gBindingId) }}" :value="__('Send test email to')" />
                                            <input id="mailtest_to_{{ md5($gBindingId) }}" type="email" wire:model="mailTestRecipient" placeholder="{{ auth()->user()?->email }}" class="dply-input mt-1 text-sm" />
                                            <button type="button" wire:click="sendBindingTestEmail(@js((string) $gBindingId))" wire:loading.attr="disabled" wire:target="sendBindingTestEmail" x-on:click="open = false" class="dply-btn dply-btn-sm dply-btn-primary mt-2 w-full">
                                                <x-heroicon-o-paper-airplane class="h-4 w-4" />
                                                {{ __('Send test email') }}
                                            </button>
                                            <p class="mt-1.5 text-xs text-brand-moss">{{ __('Sent from the site\'s server. The site must be deployed.') }}</p>
                                        </div>
                                    </div>
                                @endif
                                {{-- Secondary actions collapse into a kebab so a row never shows
                                     more than the primary test/fix actions plus this menu. --}}
                                @php
                                    $gHasOverflow = ($gManageable && method_exists($this, 'openBindingModal')) || method_exists($this, 'openBindingInfoModal') || method_exists($this, 'openDetachBindingConfirmModal');
                                @endphp
                                @if ($gHasOverflow)
                                    <x-overflow-menu>
                                        @if ($gManageable && method_exists($this, 'openBindingModal'))
                                            <button type="button" wire:click="openBindingModal('{{ $group['type'] }}', 'attach')" wire:loading.attr="disabled" wire:target="openBindingModal" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60">
                                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5 text-brand-moss" />
                                                {{ __('Update') }}
                                            </button>
                                        @endif
                                        @if (method_exists($this, 'openBindingInfoModal'))
                                            <button type="button" wire:click="openBindingInfoModal(@js((string) $gBindingId))" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                                <x-heroicon-o-information-circle class="h-3.5 w-3.5 text-brand-moss" />
                                                {{ __('Info') }}
                                            </button>
                                        @endif
                                        @if (method_exists($this, 'openDetachBindingConfirmModal'))
                                            <button type="button" wire:click="openDetachBindingConfirmModal(@js((string) $gBindingId), @js($gTypeLabel))" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-moss hover:bg-rose-50 hover:text-rose-700">
                                                <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                                                {{ __('Detach') }}
                                            </button>
                                            @if (method_exists($this, 'openDetachAndDeleteBindingConfirmModal') && $this->site->bindings->firstWhere('id', $gBindingId)?->canOfferDeleteOnDetach())
                                                <button type="button" wire:click="openDetachAndDeleteBindingConfirmModal(@js((string) $gBindingId), @js($gTypeLabel))" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-rose-800 hover:bg-rose-50">
                                                    <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                                    {{ __('Detach & delete') }}
                                                </button>
                                            @endif
                                        @endif
                                    </x-overflow-menu>
                                @endif
                            </div>
                        </div>

                        <ul class="divide-y divide-brand-ink/8" x-show="expanded" x-cloak>
                            @foreach ($group['vars'] as $mKey => $mValue)
                                @php
                                    $mEditing = ($editing_env_key ?? null) === $mKey;
                                    $mSensitive = (bool) preg_match('/(PASSWORD|SECRET|TOKEN|KEY|URL|DSN)/i', (string) $mKey);
                                @endphp
                                <li class="px-5 py-1.5 sm:px-6" wire:key="managed-env-{{ md5($mKey) }}">
                                    @if ($mEditing)
                                        {{-- Override editor: writes a real .env key that beats the binding value. --}}
                                        <form wire:submit="saveEditedEnvVar" class="space-y-3">
                                            <div class="flex flex-wrap items-end gap-3">
                                                <div class="min-w-[10rem]">
                                                    <x-input-label :value="__('Key')" />
                                                    <p class="mt-1 font-mono text-sm font-semibold text-brand-ink">{{ $mKey }}</p>
                                                </div>
                                                <div class="flex-1 min-w-[12rem]">
                                                    <x-input-label for="override_val_{{ md5($mKey) }}" :value="__('Value (override)')" />
                                                    <input
                                                        id="override_val_{{ md5($mKey) }}"
                                                        wire:model="editing_env_value"
                                                        autocomplete="off"
                                                        spellcheck="false"
                                                        class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                                                    />
                                                    <x-input-error :messages="$errors->get('editing_env_value')" class="mt-1" />
                                                </div>
                                            </div>
                                            <p class="text-xs text-brand-moss">{{ __('Saving creates a .env override for :key — it takes precedence over the :type binding until you delete the override.', ['key' => $mKey, 'type' => $gTypeLabel]) }}</p>
                                            <div class="flex items-center justify-end gap-2">
                                                <x-secondary-button type="button" wire:click="cancelEditEnvVar">{{ __('Cancel') }}</x-secondary-button>
                                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditedEnvVar">
                                                    <span wire:loading.remove wire:target="saveEditedEnvVar">{{ __('Save override') }}</span>
                                                    <span wire:loading wire:target="saveEditedEnvVar" class="inline-flex items-center gap-1.5"><span class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Saving…') }}</span>
                                                </x-primary-button>
                                            </div>
                                        </form>
                                    @else
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div class="flex min-w-0 items-center gap-3 pl-9">
                                                <div class="min-w-0">
                                                    <p class="font-mono text-sm font-semibold text-brand-ink">{{ $mKey }}</p>
                                                    <p class="mt-0.5 break-all font-mono text-xs text-brand-moss">
                                                        @if ($mValue === '')
                                                            <span class="text-brand-mist">(empty)</span>
                                                        @elseif ($mSensitive)
                                                            {{ str_repeat('•', min(24, max(4, strlen($mValue)))) }}
                                                        @else
                                                            {{ $mValue }}
                                                        @endif
                                                    </p>
                                                </div>
                                            </div>
                                            <button type="button" wire:click="overrideManagedEnvVar(@js($mKey))" class="dply-btn dply-btn-xs dply-btn-outline" title="{{ __('Set a .env value that overrides the binding.') }}">{{ __('Override') }}</button>
                                        </div>
                                    @endif
                                </li>
                            @endforeach

                            {{-- User overrides for keys provided by this binding, shown inline
                                 within the same group so "Database · tracely" is one unit. --}}
                            @if ($gGroupOverrides)
                                <li class="border-t border-amber-200/40 bg-amber-50/30 px-5 py-1.5 sm:px-6" wire:key="override-divider-{{ md5((string) $gBindingId) }}">
                                    <p class="text-2xs font-semibold uppercase tracking-[0.14em] text-amber-800">{{ __('Your overrides · take precedence at deploy') }}</p>
                                </li>
                                @foreach ($gGroupOverrides['keys'] as $oKey => $oValue)
                                    @php
                                        $oIsRevealed = in_array($oKey, $revealed_env_keys, true);
                                        $oIsEditing  = ($editing_env_key ?? null) === $oKey;
                                        $oValueLength = strlen($oValue);
                                        $oRowComment  = $envComments[$oKey] ?? null;
                                    @endphp
                                    <li class="bg-amber-50/20 px-5 py-2 sm:px-6" wire:key="env-row-{{ md5($oKey) }}">
                                        @if ($oIsEditing)
                                            <form wire:submit="saveEditedEnvVar" class="space-y-3">
                                                <div class="flex flex-wrap items-end gap-3">
                                                    <div class="flex-1 min-w-[10rem]">
                                                        <x-input-label for="og_edit_key_{{ md5($oKey) }}" :value="__('Key')" />
                                                        <x-text-input id="og_edit_key_{{ md5($oKey) }}" wire:model="editing_env_key" class="mt-1 block w-full font-mono text-sm" />
                                                        <x-input-error :messages="$errors->get('editing_env_key')" class="mt-1" />
                                                    </div>
                                                    @php $oEditHint = \App\Support\Sites\SiteEnvFieldHints::hint((string) $editing_env_key, (string) $editing_env_value); @endphp
                                                    <div class="flex-1 min-w-[12rem]" x-data="{ showValue: true }">
                                                        <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="og_edit_val_{{ md5($oKey) }}">
                                                            <span>{{ __('Value') }}@if ($oEditHint['type'] === 'bool')<span class="ml-1 font-normal text-xs text-brand-mist">{{ __('(true / false)') }}</span>@elseif ($oEditHint['type'] === 'enum')<span class="ml-1 font-normal text-xs text-brand-mist">{{ __('(pick or type)') }}</span>@endif</span>
                                                            @if ($oEditHint['type'] === 'text')
                                                                <button type="button" class="text-xs font-medium text-brand-sage hover:underline" @click="showValue = !showValue">
                                                                    <span x-show="!showValue">{{ __('Show') }}</span>
                                                                    <span x-show="showValue" x-cloak>{{ __('Hide') }}</span>
                                                                </button>
                                                            @endif
                                                        </label>
                                                        @include('livewire.sites.settings.partials.environment._value-input', ['hint' => $oEditHint, 'model' => 'editing_env_value', 'id' => 'og_edit_val_'.md5($oKey)])
                                                        <x-input-error :messages="$errors->get('editing_env_value')" class="mt-1" />
                                                    </div>
                                                </div>
                                                <div>
                                                    <x-input-label for="og_edit_comment_{{ md5($oKey) }}" :value="__('Comment (optional)')" />
                                                    <textarea id="og_edit_comment_{{ md5($oKey) }}" wire:model="editing_env_comment" rows="2" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="{{ __('Renders as a # comment line above this variable in the .env file.') }}"></textarea>
                                                    <x-input-error :messages="$errors->get('editing_env_comment')" class="mt-1" />
                                                </div>
                                                <div class="flex items-center justify-end gap-2">
                                                    <x-secondary-button type="button" wire:click="cancelEditEnvVar">{{ __('Cancel') }}</x-secondary-button>
                                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditedEnvVar">
                                                        <span wire:loading.remove wire:target="saveEditedEnvVar">{{ __('Save') }}</span>
                                                        <span wire:loading wire:target="saveEditedEnvVar" class="inline-flex items-center gap-1.5"><span class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Saving…') }}</span>
                                                    </x-primary-button>
                                                </div>
                                            </form>
                                        @else
                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                <div class="flex min-w-0 items-center gap-3 pl-9">
                                                    <div class="min-w-0">
                                                        <p class="font-mono text-sm font-semibold text-brand-ink">{{ $oKey }}</p>
                                                        <p class="mt-0.5 break-all font-mono text-xs text-brand-moss">
                                                            @if ($oIsRevealed)
                                                                {{ $oValue === '' ? '(empty)' : $oValue }}
                                                            @elseif ($oValueLength === 0)
                                                                <span class="text-brand-mist">(empty)</span>
                                                            @else
                                                                {{ str_repeat('•', min(24, max(4, $oValueLength))) }}
                                                            @endif
                                                        </p>
                                                        @if ($oRowComment !== null && $oRowComment !== '')
                                                            <p class="mt-1 whitespace-pre-line text-xs italic text-brand-mist"># {{ $oRowComment }}</p>
                                                        @endif
                                                    </div>
                                                </div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <button type="button" wire:click="toggleRevealEnvVar('{{ $oKey }}')" class="dply-btn dply-btn-xs dply-btn-outline" title="{{ $oIsRevealed ? __('Hide value') : __('Reveal value') }}">
                                                        @if ($oIsRevealed) <x-heroicon-o-eye-slash class="h-4 w-4" /> {{ __('Hide') }}
                                                        @else <x-heroicon-o-eye class="h-4 w-4" /> {{ __('Show') }}
                                                        @endif
                                                    </button>
                                                    <button type="button" wire:click="editEnvVar('{{ $oKey }}')" class="dply-btn dply-btn-xs dply-btn-outline" title="{{ __('Edit value') }}">
                                                        <x-heroicon-o-pencil-square class="h-4 w-4" /> {{ __('Edit') }}
                                                    </button>
                                                    <button type="button" wire:click="confirmRemoveEnvVar('{{ $oKey }}')" wire:loading.attr="disabled" wire:target="confirmRemoveEnvVar('{{ $oKey }}')" class="dply-btn dply-btn-xs dply-btn-outline hover:border-red-200 hover:bg-red-50 hover:text-red-700" title="{{ __('Remove override') }}">
                                                        <x-heroicon-o-trash class="h-4 w-4" wire:loading.remove wire:target="confirmRemoveEnvVar('{{ $oKey }}')" />
                                                        <span wire:loading wire:target="confirmRemoveEnvVar('{{ $oKey }}')"><x-spinner variant="forest" size="sm" /></span>
                                                        {{ __('Remove') }}
                                                    </button>
                                                </div>
                                            </div>
                                        @endif
                                    </li>
                                @endforeach
                            @endif
                        </ul>
                    </div>
                @endforeach

                {{-- Orphaned overrides: bindings that were detached but still have override keys. --}}
                @foreach ($overrideGroups as $ogBindingId => $ogGroup)
                    @if (isset($bindingManagedGroups[(string) $ogBindingId]))
                        @continue
                    @endif
                    @php
                        $ogTypeLabel = $bindingTypeLabelsInline[$ogGroup['type']] ?? (string) str($ogGroup['type'])->title();
                        $ogHasEditing = ($editing_env_key ?? null) !== null && array_key_exists((string) $editing_env_key, $ogGroup['keys']);
                    @endphp
                    <div class="border-t border-sky-200/40" wire:key="override-group-{{ md5((string) $ogBindingId) }}" x-data="{ expanded: @js($ogHasEditing) }">
                        <div class="flex flex-wrap items-center gap-2 bg-sky-50/60 px-5 py-1.5 sm:px-6">
                            <button type="button" x-on:click="expanded = ! expanded" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform" x-bind:class="expanded && 'rotate-90'" />
                                <span class="text-sm font-semibold text-brand-ink">{{ $ogTypeLabel }}</span>
                                @if ($ogGroup['name'])
                                    <span class="truncate font-mono text-xs text-brand-moss">· {{ $ogGroup['name'] }}</span>
                                @endif
                                <span class="shrink-0 rounded-full bg-amber-50 px-1.5 py-0.5 text-2xs font-semibold text-amber-800 ring-1 ring-inset ring-amber-200/70">{{ trans_choice('{1} :count override|[2,*] :count overrides', count($ogGroup['keys']), ['count' => count($ogGroup['keys'])]) }}</span>
                            </button>
                        </div>
                        <ul class="divide-y divide-brand-ink/8" x-show="expanded" x-cloak>
                            @foreach ($ogGroup['keys'] as $oKey => $oValue)
                                @php
                                    $oIsRevealed = in_array($oKey, $revealed_env_keys, true);
                                    $oIsEditing  = ($editing_env_key ?? null) === $oKey;
                                    $oValueLength = strlen($oValue);
                                    $oRowComment  = $envComments[$oKey] ?? null;
                                @endphp
                                <li class="px-5 py-2 sm:px-6" wire:key="env-row-{{ md5($oKey) }}">
                                    @if ($oIsEditing)
                                        <form wire:submit="saveEditedEnvVar" class="space-y-3">
                                            <div class="flex flex-wrap items-end gap-3">
                                                <div class="flex-1 min-w-[10rem]">
                                                    <x-input-label for="og_edit_key_{{ md5($oKey) }}" :value="__('Key')" />
                                                    <x-text-input id="og_edit_key_{{ md5($oKey) }}" wire:model="editing_env_key" class="mt-1 block w-full font-mono text-sm" />
                                                    <x-input-error :messages="$errors->get('editing_env_key')" class="mt-1" />
                                                </div>
                                                @php $oEditHint = \App\Support\Sites\SiteEnvFieldHints::hint((string) $editing_env_key, (string) $editing_env_value); @endphp
                                                <div class="flex-1 min-w-[12rem]" x-data="{ showValue: true }">
                                                    <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="og_edit_val_{{ md5($oKey) }}">
                                                        <span>{{ __('Value') }}@if ($oEditHint['type'] === 'bool')<span class="ml-1 font-normal text-xs text-brand-mist">{{ __('(true / false)') }}</span>@elseif ($oEditHint['type'] === 'enum')<span class="ml-1 font-normal text-xs text-brand-mist">{{ __('(pick or type)') }}</span>@endif</span>
                                                        @if ($oEditHint['type'] === 'text')
                                                            <button type="button" class="text-xs font-medium text-brand-sage hover:underline" @click="showValue = !showValue">
                                                                <span x-show="!showValue">{{ __('Show') }}</span>
                                                                <span x-show="showValue" x-cloak>{{ __('Hide') }}</span>
                                                            </button>
                                                        @endif
                                                    </label>
                                                    @include('livewire.sites.settings.partials.environment._value-input', ['hint' => $oEditHint, 'model' => 'editing_env_value', 'id' => 'og_edit_val_'.md5($oKey)])
                                                    <x-input-error :messages="$errors->get('editing_env_value')" class="mt-1" />
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-end gap-2">
                                                <x-secondary-button type="button" wire:click="cancelEditEnvVar">{{ __('Cancel') }}</x-secondary-button>
                                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditedEnvVar">
                                                    <span wire:loading.remove wire:target="saveEditedEnvVar">{{ __('Save') }}</span>
                                                    <span wire:loading wire:target="saveEditedEnvVar" class="inline-flex items-center gap-1.5"><span class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Saving…') }}</span>
                                                </x-primary-button>
                                            </div>
                                        </form>
                                    @else
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div class="flex min-w-0 items-center gap-3">
                                                <div class="min-w-0">
                                                    <p class="font-mono text-sm font-semibold text-brand-ink">{{ $oKey }}</p>
                                                    <p class="mt-0.5 break-all font-mono text-xs text-brand-moss">
                                                        @if ($oIsRevealed)
                                                            {{ $oValue === '' ? '(empty)' : $oValue }}
                                                        @elseif ($oValueLength === 0)
                                                            <span class="text-brand-mist">(empty)</span>
                                                        @else
                                                            {{ str_repeat('•', min(24, max(4, $oValueLength))) }}
                                                        @endif
                                                    </p>
                                                    @if ($oRowComment !== null && $oRowComment !== '')
                                                        <p class="mt-1 whitespace-pre-line text-xs italic text-brand-mist"># {{ $oRowComment }}</p>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <button type="button" wire:click="toggleRevealEnvVar('{{ $oKey }}')" class="dply-btn dply-btn-xs dply-btn-outline" title="{{ $oIsRevealed ? __('Hide value') : __('Reveal value') }}">
                                                    @if ($oIsRevealed) <x-heroicon-o-eye-slash class="h-4 w-4" /> {{ __('Hide') }}
                                                    @else <x-heroicon-o-eye class="h-4 w-4" /> {{ __('Show') }}
                                                    @endif
                                                </button>
                                                <button type="button" wire:click="editEnvVar('{{ $oKey }}')" class="dply-btn dply-btn-xs dply-btn-outline" title="{{ __('Edit value') }}">
                                                    <x-heroicon-o-pencil-square class="h-4 w-4" /> {{ __('Edit') }}
                                                </button>
                                                <button type="button" wire:click="confirmRemoveEnvVar('{{ $oKey }}')" wire:loading.attr="disabled" wire:target="confirmRemoveEnvVar('{{ $oKey }}')" class="dply-btn dply-btn-xs dply-btn-outline hover:border-red-200 hover:bg-red-50 hover:text-red-700" title="{{ __('Remove override') }}">
                                                    <x-heroicon-o-trash class="h-4 w-4" wire:loading.remove wire:target="confirmRemoveEnvVar('{{ $oKey }}')" />
                                                    <span wire:loading wire:target="confirmRemoveEnvVar('{{ $oKey }}')"><x-spinner variant="forest" size="sm" /></span>
                                                    {{ __('Remove') }}
                                                </button>
                                            </div>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Bulk-action bar: appears once one or more rows are ticked. The whole
             selection is removed in a single cache write + single SSH push. --}}
        @if (method_exists($this, 'removeSelectedEnvVars') && count($selected_env_keys) > 0)
            <div class="sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b border-brand-ink/10 bg-brand-sage/10 px-5 py-2 sm:px-6">
                <span class="text-sm font-semibold text-brand-ink">
                    {{ trans_choice('{1} :count selected|[2,*] :count selected', count($selected_env_keys), ['count' => count($selected_env_keys)]) }}
                </span>
                <div class="flex flex-wrap items-center gap-2 sm:ml-auto">
                    <button type="button" wire:click="toggleSelectAllEnvVars" class="dply-btn dply-btn-xs dply-btn-outline">
                        <x-heroicon-o-check-circle class="h-4 w-4" />
                        {{ __('Select all') }}
                    </button>
                    <button type="button" wire:click="clearEnvSelection" class="dply-btn dply-btn-xs dply-btn-outline">
                        <x-heroicon-o-x-mark class="h-4 w-4" />
                        {{ __('Clear') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmRemoveSelectedEnvVars"
                        wire:loading.attr="disabled"
                        wire:target="confirmRemoveSelectedEnvVars"
                        class="dply-btn dply-btn-xs bg-red-600 text-white hover:bg-red-700"
                    >
                        <x-heroicon-o-trash class="h-4 w-4" />
                        {{ trans_choice('{1} Remove selected|[2,*] Remove :count selected', count($selected_env_keys), ['count' => count($selected_env_keys)]) }}
                    </button>
                </div>
            </div>
        @endif

        @if ($variableCount === 0 && $bindingManagedEnv === [])
            <div class="flex flex-col items-center justify-center gap-2 px-5 py-8 text-center sm:px-8">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss">
                    <x-heroicon-o-key class="h-6 w-6" />
                </span>
                <p class="text-sm font-medium text-brand-ink">{{ __('No variables yet.') }}</p>
                <p class="text-xs text-brand-moss">{{ __('Add a variable above, connect a resource, or click Sync from server to import from an existing .env.') }}</p>
            </div>
        @elseif ($variableCount > 0)
            <ul class="divide-y divide-brand-ink/8">
                @if ($filteredEnvMap === [] && ($envSearchTerm !== '' || $selectedEnvGroup !== ''))
                    <li class="px-5 py-8 text-center text-sm text-brand-moss sm:px-8">{{ __('No variables match the current filter.') }}</li>
                @endif
                @php $residencyMap = method_exists($this, 'secretResidencyMap') ? $this->secretResidencyMap() : []; @endphp
                @foreach ($listEnvMap as $key => $value)
                    @continue(isset($overrideGroupedKeySet[$key]))
                    @php
                        $isRevealed = in_array($key, $revealed_env_keys, true);
                        $isEditing = $editing_env_key === $key;
                        $isInherited = in_array($key, $inheritedKeys, true);
                        $showDiscoveredBadge = $cacheOrigin === 'server' && ! $isInherited;
                        $valueLength = strlen($value);
                        $rowComment = $envComments[$key] ?? null;
                        $overridesBinding = $bindingProvidedKeys[$key] ?? null;
                        // Secret residency: this key's value lives off the plaintext .env
                        // (escrowed under the org key, or referenced from an external store).
                        $residency = $residencyMap[$key] ?? null;
                        $escrowRevealed = $residency && array_key_exists($key, $revealed_escrow_values ?? []);
                        $canManageResidency = method_exists($this, 'escalateEnvVar');
                    @endphp
                    <li class="px-5 py-1 transition-colors hover:bg-brand-sand/15 sm:px-6" wire:key="env-row-{{ md5($key) }}">
                        @if ($isEditing)
                            {{-- Inline edit form. Cancel reverts; Save writes and closes. --}}
                            <form wire:submit="saveEditedEnvVar" class="space-y-3">
                                <div class="flex flex-wrap items-end gap-3">
                                    <div class="flex-1 min-w-[10rem]">
                                        <x-input-label for="editing_env_key_{{ md5($key) }}" :value="__('Key')" />
                                        <x-text-input
                                            id="editing_env_key_{{ md5($key) }}"
                                            wire:model="editing_env_key"
                                            class="mt-1 block w-full font-mono text-sm"
                                        />
                                        <x-input-error :messages="$errors->get('editing_env_key')" class="mt-1" />
                                    </div>
                                    @php $editHint = \App\Support\Sites\SiteEnvFieldHints::hint((string) $editing_env_key, (string) $editing_env_value); @endphp
                                    <div class="flex-1 min-w-[12rem]" x-data="{ showValue: true }">
                                        <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="editing_env_value_{{ md5($key) }}">
                                            <span>{{ __('Value') }}@if ($editHint['type'] === 'bool')<span class="ml-1 font-normal text-xs text-brand-mist">{{ __('(true / false)') }}</span>@elseif ($editHint['type'] === 'enum')<span class="ml-1 font-normal text-xs text-brand-mist">{{ __('(pick or type)') }}</span>@endif</span>
                                            @if ($editHint['type'] === 'text')
                                                <button type="button" class="text-xs font-medium text-brand-sage hover:underline" @click="showValue = !showValue">
                                                    <span x-show="!showValue">{{ __('Show') }}</span>
                                                    <span x-show="showValue" x-cloak>{{ __('Hide') }}</span>
                                                </button>
                                            @endif
                                        </label>
                                        @include('livewire.sites.settings.partials.environment._value-input', ['hint' => $editHint, 'model' => 'editing_env_value', 'id' => 'editing_env_value_'.md5($key)])
                                        <x-input-error :messages="$errors->get('editing_env_value')" class="mt-1" />
                                    </div>
                                </div>
                                <div>
                                    <x-input-label for="editing_env_comment_{{ md5($key) }}" :value="__('Comment (optional)')" />
                                    <textarea
                                        id="editing_env_comment_{{ md5($key) }}"
                                        wire:model="editing_env_comment"
                                        rows="2"
                                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                        placeholder="{{ __('Renders as a # comment line above this variable in the .env file.') }}"
                                    ></textarea>
                                    <x-input-error :messages="$errors->get('editing_env_comment')" class="mt-1" />
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <x-secondary-button type="button" wire:click="cancelEditEnvVar">{{ __('Cancel') }}</x-secondary-button>
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditedEnvVar">
                                        <span wire:loading.remove wire:target="saveEditedEnvVar">{{ __('Save') }}</span>
                                        <span wire:loading wire:target="saveEditedEnvVar" class="inline-flex items-center gap-1.5"><span class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Saving…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        @else
                            {{-- Key and value share one line, with the key column a
                                 fixed width so values align into a scannable column.
                                 Stacking value under key doubled every row's height —
                                 25 rows of that is most of the page. --}}
                            <div class="flex items-center gap-3">
                                <div class="flex min-w-0 flex-1 items-center gap-2.5">
                                    @if (method_exists($this, 'removeSelectedEnvVars'))
                                        <input
                                            type="checkbox"
                                            value="{{ $key }}"
                                            wire:model.live="selected_env_keys"
                                            aria-label="{{ __('Select :key for bulk actions', ['key' => $key]) }}"
                                            class="h-3.5 w-3.5 shrink-0 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/40"
                                        />
                                    @endif
                                    <div class="flex min-w-0 shrink-0 items-center gap-1 sm:w-64">
                                        <span class="truncate font-mono text-xs font-semibold text-brand-ink" title="{{ $key }}">{{ $key }}</span>
                                        {{-- Badges are icon-only with the label in the
                                             tooltip and sr-only text. Four uppercase,
                                             letter-spaced pills on one row crowded out
                                             the value they were annotating. --}}
                                        @if ($showDiscoveredBadge)
                                            <span
                                                class="inline-flex shrink-0 items-center rounded bg-sky-50 p-0.5 text-sky-800 ring-1 ring-inset ring-sky-200/70"
                                                title="{{ __('Discovered — imported from the live .env on the server.') }}"
                                            >
                                                <x-heroicon-m-magnifying-glass class="h-3 w-3" />
                                                <span class="sr-only">{{ __('Discovered') }}</span>
                                            </span>
                                        @endif
                                        @if ($isInherited)
                                            <span
                                                class="inline-flex shrink-0 items-center rounded bg-amber-50 p-0.5 text-amber-900 ring-1 ring-inset ring-amber-200/70"
                                                title="{{ __('Override — this site key overrides a workspace-inherited variable.') }}"
                                            >
                                                <x-heroicon-m-link class="h-3 w-3" />
                                                <span class="sr-only">{{ __('Override') }}</span>
                                            </span>
                                        @endif
                                        @if ($overridesBinding)
                                            <span
                                                class="inline-flex shrink-0 items-center rounded bg-sky-50 p-0.5 text-sky-800 ring-1 ring-inset ring-sky-200/70"
                                                title="{{ __('This .env value overrides the :type binding\'s connection variable.', ['type' => $bindingTypeLabelsInline[$overridesBinding['type']] ?? $overridesBinding['type']]) }}"
                                            >
                                                <x-heroicon-m-link class="h-3 w-3" />
                                                <span class="sr-only">{{ __('Overrides :type', ['type' => $bindingTypeLabelsInline[$overridesBinding['type']] ?? $overridesBinding['type']]) }}</span>
                                            </span>
                                        @endif
                                        @if ($residency)
                                            <span
                                                class="inline-flex shrink-0 items-center rounded bg-brand-forest/10 p-0.5 text-brand-forest ring-1 ring-inset ring-brand-forest/20"
                                                title="{{ $residency['mode'] === 'external' ? __('External — value is referenced from an external secret store; it is never stored in dply.') : __('Org key — value is encrypted under your organization key, not stored in the plaintext .env.') }}"
                                            >
                                                <x-heroicon-m-lock-closed class="h-3 w-3" />
                                                <span class="sr-only">{{ $residency['mode'] === 'external' ? __('External') : __('Org key') }}</span>
                                            </span>
                                        @endif
                                    </div>

                                    <p class="min-w-0 flex-1 truncate font-mono text-xs text-brand-moss">
                                        @if ($residency)
                                            @if ($escrowRevealed)
                                                {{ $revealed_escrow_values[$key] === '' ? '(empty)' : $revealed_escrow_values[$key] }}
                                            @elseif ($residency['mode'] === 'external')
                                                <span class="text-brand-mist">{{ __('resolved from external store at deploy') }}</span>
                                            @else
                                                <span class="text-brand-mist">{{ __('held in the organization key') }}</span>
                                            @endif
                                        @elseif ($isRevealed)
                                            {{ $value === '' ? '(empty)' : $value }}
                                        @else
                                            @if ($valueLength === 0)
                                                <span class="text-brand-mist">(empty)</span>
                                            @else
                                                {{ str_repeat('•', min(24, max(4, $valueLength))) }}
                                            @endif
                                        @endif
                                    </p>
                                </div>

                                <div class="flex shrink-0 items-center gap-1">
                                    @if ($residency)
                                        @if ($residency['mode'] !== 'external' && $residency['can_reveal'])
                                            <button
                                                type="button"
                                                wire:click="revealEscrowedEnvVar('{{ $key }}')"
                                                class="dply-btn dply-btn-xs dply-btn-outline"
                                                title="{{ $escrowRevealed ? __('Hide value') : __('Reveal value') }}"
                                            >
                                                @if ($escrowRevealed)
                                                    <x-heroicon-o-eye-slash class="h-4 w-4" />{{ __('Hide') }}
                                                @else
                                                    <x-heroicon-o-eye class="h-4 w-4" />{{ __('Reveal') }}
                                                @endif
                                            </button>
                                        @endif
                                        @if ($residency['mode'] !== 'external' && $canManageResidency)
                                            <button
                                                type="button"
                                                wire:click="demoteEnvVar('{{ $key }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="demoteEnvVar('{{ $key }}')"
                                                class="dply-btn dply-btn-xs dply-btn-outline"
                                                title="{{ __('Move this secret back into the editable .env') }}"
                                            >
                                                <x-heroicon-o-lock-open class="h-4 w-4" />{{ __('Move back') }}
                                            </button>
                                        @endif
                                    @else
                                    {{-- Show + Edit stay inline; Import / Remove / Move-to-org-key
                                         collapse into a kebab so the row stays to two buttons.
                                         Icon-only: the labels repeated identically down all 25
                                         rows and ate the width the value column needed. --}}
                                    <button
                                        type="button"
                                        wire:click="toggleRevealEnvVar('{{ $key }}')"
                                        class="rounded p-1 text-brand-moss transition-colors hover:bg-brand-sand/40 hover:text-brand-ink"
                                        title="{{ $isRevealed ? __('Hide value') : __('Reveal value') }}"
                                    >
                                        @if ($isRevealed)
                                            <x-heroicon-o-eye-slash class="h-4 w-4" />
                                            <span class="sr-only">{{ __('Hide :key', ['key' => $key]) }}</span>
                                        @else
                                            <x-heroicon-o-eye class="h-4 w-4" />
                                            <span class="sr-only">{{ __('Show :key', ['key' => $key]) }}</span>
                                        @endif
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="editEnvVar('{{ $key }}')"
                                        class="rounded p-1 text-brand-moss transition-colors hover:bg-brand-sand/40 hover:text-brand-ink"
                                        title="{{ __('Edit value') }}"
                                    >
                                        <x-heroicon-o-pencil-square class="h-4 w-4" />
                                        <span class="sr-only">{{ __('Edit :key', ['key' => $key]) }}</span>
                                    </button>
                                    <x-overflow-menu>
                                        <button type="button" wire:click="$set('env_import_key', '{{ $key }}')" x-on:click="$dispatch('open-modal', 'env-import-modal')" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40" title="{{ __('Import :key from another site', ['key' => $key]) }}">
                                            <x-heroicon-o-arrow-down-on-square class="h-3.5 w-3.5 text-brand-moss" />
                                            {{ __('Import') }}
                                        </button>
                                        @if ($canManageResidency && $valueLength > 0)
                                            <button type="button" wire:click="escalateEnvVar('{{ $key }}')" wire:loading.attr="disabled" wire:target="escalateEnvVar('{{ $key }}')" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-forest/5 hover:text-brand-forest disabled:opacity-40" title="{{ __('Encrypt this value under your organization key and keep it out of the plaintext .env') }}">
                                                <x-heroicon-o-lock-closed class="h-3.5 w-3.5 text-brand-moss" />
                                                {{ __('Move to org key') }}
                                            </button>
                                        @endif
                                        <button type="button" wire:click="confirmRemoveEnvVar('{{ $key }}')" wire:loading.attr="disabled" wire:target="confirmRemoveEnvVar('{{ $key }}')" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-moss hover:bg-rose-50 hover:text-rose-700 disabled:opacity-40" title="{{ __('Remove variable') }}">
                                            <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                            {{ __('Remove') }}
                                        </button>
                                    </x-overflow-menu>
                                    @endif
                                </div>
                            </div>
                            @if ($rowComment !== null && $rowComment !== '')
                                {{-- Comment sits under the row (plain, not mono, so it
                                     separates from the KEY/value pair) and only costs a
                                     line on the few rows that carry one. --}}
                                <p class="mt-0.5 whitespace-pre-line pl-6 text-xs italic text-brand-mist">
                                    # {{ $rowComment }}
                                </p>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($envAdvanced && $envTotalPages > 1)
                @php
                    $envFrom = ($envCurrentPage - 1) * $envPerPage + 1;
                    $envTo = min($envCurrentPage * $envPerPage, $envFilteredCount);
                @endphp
                <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 px-5 py-2 sm:px-6">
                    <span class="text-xs text-brand-mist">{{ __(':from–:to of :total', ['from' => $envFrom, 'to' => $envTo, 'total' => $envFilteredCount]) }}</span>
                    <div class="flex items-center gap-1.5">
                        <button type="button" wire:click="$set('env_page', {{ max(1, $envCurrentPage - 1) }})" @disabled($envCurrentPage <= 1) class="dply-btn dply-btn-xs dply-btn-outline">
                            <x-heroicon-o-chevron-left class="h-3 w-3" />
                            {{ __('Prev') }}
                        </button>
                        <span class="px-1 text-xs font-semibold text-brand-moss">{{ __('Page :p / :n', ['p' => $envCurrentPage, 'n' => $envTotalPages]) }}</span>
                        <button type="button" wire:click="$set('env_page', {{ min($envTotalPages, $envCurrentPage + 1) }})" @disabled($envCurrentPage >= $envTotalPages) class="dply-btn dply-btn-xs dply-btn-outline">
                            {{ __('Next') }}
                            <x-heroicon-o-chevron-right class="h-3 w-3" />
                        </button>
                    </div>
                </div>
            @endif
        @endif
    </div>
