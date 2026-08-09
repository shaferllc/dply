{{-- Nested inside Settings Authentication merged card — hairline strips, no nested dply-cards. --}}
@php
    $supportsBA = $site->supportsBasicAuthProvisioning();
    $supportsFormGate = $site->webserverSupportsFormPasswordGate();
    $accessMethod = $access_gate_method !== '' ? $access_gate_method : $site->resolvedAccessGateMethod();
    $supportsPathPrefixes = $supportsBA && $site->basicAuthSupportsPathPrefixes();
    // Caddy is the only engine that can't enforce non-bcrypt hashes (apr1, sha).
    // Other engines read htpasswd directly via files, which support all formats.
    // We surface a "Rotate to enforce" chip on Caddy sites for any row that
    // arrived from Sync-from-server with a non-bcrypt hash.
    $isCaddy = $site->webserver() === 'caddy';
    $entries = $site->basicAuthUsers;
    // The active count drives the "N credentials" pill and the empty-state copy.
    // Pending-removal rows still render in the list (with a "Removing" badge) so
    // the operator can see the apply is mid-flight, but they shouldn't count as
    // gates that are actually live on the server.
    $activeEntries = $entries->reject(fn ($u) => $u->isPendingRemoval());
    $entryCount = $activeEntries->count();
    $pendingCount = $entries->count() - $entryCount;
    $latestUpdated = $entries->max('updated_at');
    // Group entries by normalized path so users can scan "what's protected" without
    // mentally bucketing a flat list. Single-path setups still render cleanly: one group.
    $byPath = $entries->groupBy(fn ($u) => $u->normalizedPath());
    $protectedPaths = $byPath->keys()->sort()->values();
    // Resolve once for all per-path test snippets so we don't re-query SiteDomain
    // per rendered group.
    $primaryHost = $site->primaryDomain()?->hostname ?? 'example.com';
@endphp

<div class="min-w-0">
    {{-- Webserver apply / sync progress streams to the embedded console banner
         above this strip (auto-scrolled into view when you add, remove, or rotate). --}}

    @if (! $supportsBA)
        <div class="border-b border-amber-200/80 bg-amber-50/60 px-3 py-2.5 sm:px-4">
            <div class="flex items-start gap-2">
                <x-heroicon-o-shield-exclamation class="mt-0.5 h-4 w-4 shrink-0 text-amber-800" aria-hidden="true" />
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Basic auth unavailable on this runtime') }}</h3>
                    <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">{{ __('Basic authentication applies to VM sites with managed web server configuration. Container and serverless runtimes use their own access controls.') }}</p>
                </div>
            </div>
        </div>
    @else
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-shield-check"
                :title="__('How visitors authenticate')"
                :note="__('Only one method active. Changes apply via the webserver job in the banner.')"
            />
            <div class="grid gap-2 px-3 py-2.5 sm:grid-cols-3 sm:px-4">
                <button
                    type="button"
                    wire:click="selectAccessGateMethod('off')"
                    @class([
                        'rounded-lg border px-3 py-2.5 text-left transition',
                        'border-brand-forest bg-brand-sage/10 ring-1 ring-brand-forest/30' => $accessMethod === 'off',
                        'border-brand-ink/15 bg-white hover:bg-brand-sand/30' => $accessMethod !== 'off',
                    ])
                >
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-x-circle class="h-4 w-4 text-brand-mist" />
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Off') }}</p>
                    </div>
                    <p class="mt-1 text-[11px] leading-relaxed text-brand-moss">{{ __('No gate — visitors reach the app directly.') }}</p>
                </button>
                <button
                    type="button"
                    wire:click="selectAccessGateMethod('basic_auth')"
                    @class([
                        'rounded-lg border px-3 py-2.5 text-left transition',
                        'border-brand-forest bg-brand-sage/10 ring-1 ring-brand-forest/30' => $accessMethod === 'basic_auth',
                        'border-brand-ink/15 bg-white hover:bg-brand-sand/30' => $accessMethod !== 'basic_auth',
                    ])
                >
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-lock-closed class="h-4 w-4 text-brand-mist" />
                        <p class="text-sm font-semibold text-brand-ink">{{ __('HTTP basic auth') }}</p>
                    </div>
                    <p class="mt-1 text-[11px] leading-relaxed text-brand-moss">{{ __('Browser popup, multiple users, optional path prefixes.') }}</p>
                </button>
                <button
                    type="button"
                    wire:click="selectAccessGateMethod('form_password')"
                    @disabled(! $supportsFormGate)
                    @class([
                        'rounded-lg border px-3 py-2.5 text-left transition',
                        'border-brand-forest bg-brand-sage/10 ring-1 ring-brand-forest/30' => $accessMethod === 'form_password',
                        'border-brand-ink/15 bg-white hover:bg-brand-sand/30' => $accessMethod !== 'form_password',
                        'cursor-not-allowed opacity-60' => ! $supportsFormGate,
                    ])
                >
                    <div class="flex items-center gap-1.5">
                        <x-heroicon-o-key class="h-4 w-4 text-brand-mist" />
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Password gate') }}</p>
                    </div>
                    <p class="mt-1 text-[11px] leading-relaxed text-brand-moss">
                        @if ($supportsFormGate)
                            {{ __('Styled login page + cookie — no browser basic-auth dialog.') }}
                        @else
                            {{ __('Coming soon for OpenLiteSpeed.') }}
                        @endif
                    </p>
                </button>
            </div>
        </section>

        @if ($accessMethod === 'form_password' && $supportsFormGate)
            @php
                $gatePasswords = $site->accessGatePasswords;
                $activeGatePasswords = $gatePasswords->reject(fn ($row) => $row->isPendingRemoval());
                $gatePasswordCount = $activeGatePasswords->count();
            @endphp
            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-key"
                    :title="__('Named gate passwords')"
                    :note="trans_choice('{0} No passwords yet — cookie lasts 24h after login.|{1} :count password · cookie lasts 24h.|[2,*] :count passwords · cookie lasts 24h.', $gatePasswordCount, ['count' => $gatePasswordCount])"
                >
                    <x-slot:actions>
                        <button
                            type="button"
                            x-data=""
                            x-on:click.prevent="$dispatch('open-modal', 'add-form-gate-modal')"
                            class="inline-flex items-center gap-1 rounded-lg bg-brand-forest px-2.5 py-1 text-[11px] font-semibold text-white shadow-sm hover:bg-brand-sage"
                        >
                            <x-heroicon-m-plus class="h-3.5 w-3.5" />
                            {{ __('Add') }}
                        </button>
                        @if ($gatePasswordCount > 0)
                            <button
                                type="button"
                                wire:click="disableFormGatePassword"
                                wire:loading.attr="disabled"
                                wire:target="disableFormGatePassword"
                                class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <x-heroicon-o-x-circle class="h-3.5 w-3.5" wire:loading.remove wire:target="disableFormGatePassword" />
                                <x-spinner wire:loading wire:target="disableFormGatePassword" size="sm" />
                                <span wire:loading.remove wire:target="disableFormGatePassword">{{ __('Remove gate') }}</span>
                                <span wire:loading wire:target="disableFormGatePassword">{{ __('Removing…') }}</span>
                            </button>
                        @endif
                    </x-slot:actions>
                </x-workspace-panel-head>

                @if ($gatePasswords->isNotEmpty())
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($gatePasswords as $gatePassword)
                            <li @class(['px-3 py-2.5 sm:px-4', 'opacity-60' => $gatePassword->isPendingRemoval()])>
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-semibold text-brand-ink">{{ $gatePassword->label }}</p>
                                        <p class="mt-0.5 text-xs text-brand-moss">
                                            @if ($gatePassword->isPendingRemoval())
                                                {{ __('Removing on next webserver apply') }}
                                            @else
                                                {{ __('Active gate password') }}
                                            @endif
                                        </p>
                                    </div>
                                    @if (! $gatePassword->isPendingRemoval())
                                        <button
                                            type="button"
                                            wire:click="confirmRemoveFormGatePassword('{{ $gatePassword->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="confirmRemoveFormGatePassword('{{ $gatePassword->id }}')"
                                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            <x-heroicon-o-trash class="h-3.5 w-3.5" wire:loading.remove wire:target="confirmRemoveFormGatePassword('{{ $gatePassword->id }}')" />
                                            <x-spinner wire:loading wire:target="confirmRemoveFormGatePassword('{{ $gatePassword->id }}')" size="sm" />
                                            <span wire:loading.remove wire:target="confirmRemoveFormGatePassword('{{ $gatePassword->id }}')">{{ __('Remove') }}</span>
                                            <span wire:loading wire:target="confirmRemoveFormGatePassword('{{ $gatePassword->id }}')">{{ __('Removing…') }}</span>
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <div class="px-3 py-4 sm:px-4">
                        <p class="text-sm text-brand-moss">{{ __('No gate passwords yet. Add one to enable the login form — until then the site will not require the gate on apply.') }}</p>
                    </div>
                @endif
            </section>

            <section class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    class="border-b border-brand-ink/10"
                    icon="heroicon-o-clock"
                    :title="__('Login log')"
                    :note="__('Successful logins with label, IP, and time.')"
                />

                @if (! $form_gate_login_log_loaded)
                    <div wire:init="loadFormGateLoginLog" class="flex items-center justify-center gap-2 px-3 py-5 text-sm text-brand-moss sm:px-4">
                        <x-spinner variant="forest" size="sm" />
                        {{ __('Reading login log…') }}
                    </div>
                @elseif ($form_gate_login_log === [])
                    <div class="px-3 py-4 text-center sm:px-4">
                        <p class="text-sm text-brand-moss">{{ __('No logins recorded yet.') }}</p>
                    </div>
                @else
                    <div x-data="{ selected: null }">
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($form_gate_login_log as $entry)
                                @php $at = \Carbon\Carbon::parse($entry['at']); @endphp
                                <li>
                                    <button
                                        type="button"
                                        @click="selected = @js(array_merge($entry, ['at_human' => $at->diffForHumans(), 'at_full' => $at->toDayDateTimeString(), 'at_iso' => $at->toIso8601String()]))"
                                        class="flex w-full items-center justify-between gap-4 px-3 py-2 text-left hover:bg-brand-sand/20 sm:px-4"
                                    >
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-semibold text-brand-ink">{{ $entry['label'] }}</p>
                                            <p class="mt-0.5 text-xs text-brand-moss">
                                                @if (! empty($entry['ip'])){{ $entry['ip'] }}@endif
                                                @if (! empty($entry['hostname']))
                                                    @if (! empty($entry['ip'])) · @endif
                                                    {{ $entry['hostname'] }}
                                                @endif
                                            </p>
                                            @if (! empty($entry['user_agent']))
                                                <p class="mt-0.5 truncate text-[11px] text-brand-mist">{{ $entry['user_agent'] }}</p>
                                            @endif
                                        </div>
                                        <div class="flex shrink-0 items-center gap-2">
                                            <time
                                                class="text-xs text-brand-mist"
                                                datetime="{{ $at->toIso8601String() }}"
                                                title="{{ $at->toDayDateTimeString() }}"
                                            >{{ $at->diffForHumans() }}</time>
                                            <x-heroicon-m-chevron-right class="h-3.5 w-3.5 text-brand-mist/50" />
                                        </div>
                                    </button>
                                </li>
                            @endforeach
                        </ul>

                        {{-- Detail modal --}}
                        <div
                            x-show="selected"
                            x-cloak
                            x-on:keydown.escape.window="selected = null"
                            class="fixed inset-0 z-50 flex items-center justify-center p-4"
                            role="dialog"
                            aria-modal="true"
                        >
                            <div class="absolute inset-0 bg-brand-ink/30" @click="selected = null" aria-hidden="true"></div>
                            <div
                                class="relative w-full max-w-md overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-xl"
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                            >
                                <div class="flex items-center justify-between border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4">
                                    <div>
                                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Login detail') }}</p>
                                        <p class="mt-0.5 text-base font-semibold text-brand-ink" x-text="selected?.label"></p>
                                    </div>
                                    <button type="button" @click="selected = null" class="rounded-lg p-1.5 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                                        <x-heroicon-o-x-mark class="h-5 w-5" />
                                    </button>
                                </div>
                                <dl class="divide-y divide-brand-ink/8 px-6 py-2">
                                    <div class="flex items-start justify-between gap-4 py-3">
                                        <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Time') }}</dt>
                                        <dd class="min-w-0 text-right text-xs text-brand-ink">
                                            <span x-text="selected?.at_human"></span>
                                            <span class="block text-[10px] text-brand-mist" x-text="selected?.at_full"></span>
                                        </dd>
                                    </div>
                                    <template x-if="selected?.ip">
                                        <div class="flex items-center justify-between gap-4 py-3">
                                            <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('IP address') }}</dt>
                                            <dd class="font-mono text-xs text-brand-ink" x-text="selected?.ip"></dd>
                                        </div>
                                    </template>
                                    <template x-if="selected?.hostname">
                                        <div class="flex items-center justify-between gap-4 py-3">
                                            <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Hostname') }}</dt>
                                            <dd class="min-w-0 break-all text-right font-mono text-xs text-brand-ink" x-text="selected?.hostname"></dd>
                                        </div>
                                    </template>
                                    <template x-if="selected?.user_agent">
                                        <div class="flex items-start justify-between gap-4 py-3">
                                            <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('User agent') }}</dt>
                                            <dd class="min-w-0 break-all text-right text-xs text-brand-ink" x-text="selected?.user_agent"></dd>
                                        </div>
                                    </template>
                                    <template x-if="selected?.credential_id">
                                        <div class="flex items-center justify-between gap-4 py-3">
                                            <dt class="shrink-0 text-xs font-medium text-brand-mist">{{ __('Credential ID') }}</dt>
                                            <dd class="font-mono text-[10px] text-brand-mist" x-text="selected?.credential_id"></dd>
                                        </div>
                                    </template>
                                </dl>
                                <div class="border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-3">
                                    <p class="text-[11px] text-brand-moss">{{ __('Recorded on the server at the time of gate authentication.') }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </section>

            <x-modal name="add-form-gate-modal" maxWidth="md">
                <form wire:submit="addFormGatePassword" class="space-y-4 p-6">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Add gate password') }}</h2>
                    <p class="text-sm text-brand-moss">{{ __('Use a label like a person or team name so logins are attributable.') }}</p>
                    <div>
                        <x-input-label for="new_form_gate_label" :value="__('Label')" />
                        <x-text-input id="new_form_gate_label" wire:model="new_form_gate_label" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Sarah, Agency preview') }}" />
                        <x-input-error :messages="$errors->get('new_form_gate_label')" class="mt-1" />
                    </div>
                    <div x-data="{
                        showPassword: false,
                        copied: false,
                        async copyPassword() {
                            const v = document.getElementById('form_gate_password')?.value || '';
                            if (!v) return;
                            try { await navigator.clipboard.writeText(v); this.copied = true; setTimeout(() => this.copied = false, 1800); } catch (e) {}
                        },
                    }">
                        <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="form_gate_password">
                            <span>{{ __('Password') }}</span>
                            <span class="flex items-center gap-3 text-xs">
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="copyPassword()">
                                    <span x-show="!copied">{{ __('Copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                </button>
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="showPassword = !showPassword">
                                    <span x-show="!showPassword">{{ __('Show') }}</span>
                                    <span x-show="showPassword" x-cloak>{{ __('Hide') }}</span>
                                </button>
                                <button type="button" wire:click="generateFormGatePassword" class="font-medium text-brand-sage hover:underline">
                                    {{ __('Generate') }}
                                </button>
                            </span>
                        </label>
                        <input
                            id="form_gate_password"
                            wire:model="form_gate_password"
                            x-bind:type="showPassword ? 'text' : 'password'"
                            autocomplete="new-password"
                            spellcheck="false"
                            class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                        />
                        <x-input-error :messages="$errors->get('form_gate_password')" class="mt-1" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'add-form-gate-modal')">{{ __('Cancel') }}</x-secondary-button>
                        <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="addFormGatePassword">
                            <span wire:loading.remove wire:target="addFormGatePassword">{{ __('Save password') }}</span>
                            <span wire:loading wire:target="addFormGatePassword">{{ __('Saving…') }}</span>
                        </x-primary-button>
                    </div>
                </form>
            </x-modal>
        @elseif ($accessMethod === 'basic_auth')
        @php
            $basicAuthNoteParts = [
                trans_choice('{0} no credentials|{1} :count credential|[2,*] :count credentials', $entryCount, ['count' => $entryCount]),
            ];
            if ($protectedPaths->isNotEmpty()) {
                $basicAuthNoteParts[] = trans_choice('{1} :count path|[2,*] :count paths', $protectedPaths->count(), ['count' => $protectedPaths->count()]);
            }
            if ($latestUpdated) {
                $basicAuthNoteParts[] = __('updated :time', ['time' => $latestUpdated->diffForHumans()]);
            }
            $basicAuthNote = implode(' · ', $basicAuthNoteParts);
        @endphp
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-lock-closed"
                :title="__('HTTP basic authentication')"
                :note="$basicAuthNote"
            >
                <x-slot:actions>
                    {{-- Pulls leftover .htpasswd files from inside the site repo back into
                         the database so they show up below and can be removed via the
                         normal flow. --}}
                    <button
                        type="button"
                        wire:click="syncBasicAuthFromServer"
                        wire:loading.attr="disabled"
                        wire:target="syncBasicAuthFromServer"
                        class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                        title="{{ __('Scan the server for .htpasswd files inside this site\'s repo and import any users we don\'t already track.') }}"
                    >
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="syncBasicAuthFromServer" />
                        <span wire:loading wire:target="syncBasicAuthFromServer" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                            <x-spinner variant="forest" size="sm" />
                        </span>
                        <span wire:loading.remove wire:target="syncBasicAuthFromServer">{{ __('Sync') }}</span>
                        <span wire:loading wire:target="syncBasicAuthFromServer">{{ __('Scanning…') }}</span>
                    </button>
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'add-basic-auth-modal')"
                        class="inline-flex items-center gap-1 rounded-lg bg-brand-forest px-2.5 py-1 text-[11px] font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5" />
                        {{ __('Add') }}
                    </button>
                </x-slot:actions>
            </x-workspace-panel-head>
        </section>

        {{-- Add credential modal: single entry form on top, bulk-import disclosure underneath.
             Mirrors the "Add SSH key" modal pattern (Profile / Paste / Generate). --}}
        <x-modal name="add-basic-auth-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Basic auth credential') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add a credential') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('Enter a username, set or generate a password, and choose which path it protects.') }}
                </p>
            </div>

            <div
                class="px-6 py-6"
                x-data="{
                    /* Local Alpine state mirrored from Livewire via $wire.$watch — reading
                       $wire.* directly inside getters does NOT register as a reactive
                       dependency in Alpine, so the auth-header preview stays empty even
                       when fields are filled. Watching wires up the change feed (covers
                       both typing in the inputs AND the Generate button's wire:click
                       roundtrip pushing a new password value). */
                    username: '',
                    password: '',
                    copiedHeader: false,
                    init() {
                        this.username = (this.$wire.new_basic_auth_username || '').toString();
                        this.password = (this.$wire.new_basic_auth_password || '').toString();
                        this.$wire.$watch('new_basic_auth_username', (v) => { this.username = (v || '').toString(); });
                        this.$wire.$watch('new_basic_auth_password', (v) => { this.password = (v || '').toString(); });
                    },
                    get authHeader() {
                        const u = this.username.trim();
                        const p = this.password;
                        if (!u || !p) return '';
                        try { return 'Basic ' + btoa(u + ':' + p); } catch (e) { return ''; }
                    },
                    async copyHeader() {
                        const h = this.authHeader;
                        if (!h) return;
                        try {
                            await navigator.clipboard.writeText(h);
                            this.copiedHeader = true;
                            setTimeout(() => this.copiedHeader = false, 1800);
                        } catch (e) {}
                    },
                }"
            >
                <form wire:submit="addBasicAuthUser" id="add-basic-auth-form" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="new_basic_auth_username" :value="__('Username')" />
                            <x-text-input
                                id="new_basic_auth_username"
                                wire:model.live="new_basic_auth_username"
                                class="mt-1 block w-full font-mono text-sm"
                                autocomplete="off"
                                placeholder="{{ __('e.g. staging') }}"
                            />
                            <x-input-error :messages="$errors->get('new_basic_auth_username')" class="mt-1" />
                        </div>
                        {{-- Mirrors the rotate-reveal modal's password area: label on the
                             left with Copy + Generate as inline links on the right, a single
                             clean input below. We keep wire:model.live so the Authorization-header
                             block in the wrapping x-data sees the value live. --}}
                        <div x-data="{
                                copied: false,
                                showPassword: false,
                                async copyPassword() {
                                    const v = document.getElementById('new_basic_auth_password')?.value || '';
                                    if (!v) return;
                                    try { await navigator.clipboard.writeText(v); this.copied = true; setTimeout(() => this.copied = false, 1800); } catch (e) {}
                                },
                        }">
                            <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="new_basic_auth_password">
                                <span>{{ __('Password') }}</span>
                                <span class="flex items-center gap-3 text-xs">
                                    <button type="button" class="font-medium text-brand-sage hover:underline" @click="copyPassword()">
                                        <span x-show="!copied">{{ __('Copy') }}</span>
                                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                    </button>
                                    <button type="button" class="font-medium text-brand-sage hover:underline" @click="showPassword = !showPassword">
                                        <span x-show="!showPassword">{{ __('Show') }}</span>
                                        <span x-show="showPassword" x-cloak>{{ __('Hide') }}</span>
                                    </button>
                                    <button type="button" wire:click="generateBasicAuthPassword" class="font-medium text-brand-sage hover:underline">
                                        {{ __('Generate') }}
                                    </button>
                                </span>
                            </label>
                            <input
                                id="new_basic_auth_password"
                                wire:model.live="new_basic_auth_password"
                                x-bind:type="showPassword ? 'text' : 'password'"
                                autocomplete="new-password"
                                spellcheck="false"
                                class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                            />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Stored as a bcrypt hash. Minimum 8 characters.') }}</p>
                            <x-input-error :messages="$errors->get('new_basic_auth_password')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="new_basic_auth_path" :value="__('Path')" />
                        <x-text-input
                            id="new_basic_auth_path"
                            wire:model="new_basic_auth_path"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="/"
                            :disabled="! $supportsPathPrefixes"
                        />
                        <p class="mt-1 text-xs text-brand-moss">
                            @if ($supportsPathPrefixes)
                                {{ __('Use / for the whole site, or a prefix like /wp-admin.') }}
                            @else
                                {{ __('This site type only supports / (whole site) for basic auth.') }}
                            @endif
                        </p>
                        <x-input-error :messages="$errors->get('new_basic_auth_path')" class="mt-1" />
                    </div>

                    {{-- Live Authorization-header preview. Mirrors the box in the rotate-
                         reveal modal so the operator can verify their credential with
                         curl -H "Authorization: Basic <…>" the moment they finish typing
                         (or pressing Generate). Hidden until both username and password
                         are non-empty so it doesn't dangle as a half-built header. --}}
                    <div
                        x-show="authHeader"
                        x-cloak
                        class="rounded-xl border border-brand-sage/25 bg-brand-sage/5 px-4 py-3"
                    >
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Authorization header') }}</p>
                            <button
                                type="button"
                                @click="copyHeader()"
                                class="text-xs font-semibold text-brand-forest hover:underline"
                            >
                                <span x-show="!copiedHeader">{{ __('Copy header') }}</span>
                                <span x-show="copiedHeader" x-cloak>{{ __('Copied') }}</span>
                            </button>
                        </div>
                        <p class="mt-1 break-all font-mono text-[11px] text-brand-moss" x-text="authHeader"></p>
                        <p class="mt-1 text-[11px] text-brand-moss">
                            {{ __('Standard HTTP Basic auth — base64 of username:password. Drop into curl -H or a proxy config to verify the credential.') }}
                        </p>
                    </div>
                </form>

                {{-- Bulk import: paste user:secret lines. Accepts plaintext (we hash) or
                     already-hashed bcrypt/apr1/sha entries copied from an existing htpasswd. --}}
                <details class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                    <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-brand-mist">
                        <span class="inline-flex items-center gap-1.5">
                            <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                            {{ __('Bulk import — paste from an existing htpasswd') }}
                        </span>
                    </summary>
                    <form wire:submit="bulkImportBasicAuth" class="mt-3 space-y-3">
                        <div>
                            <x-input-label for="bulk_basic_auth_path" :value="__('Path for these users')" />
                            <x-text-input
                                id="bulk_basic_auth_path"
                                wire:model="bulk_basic_auth_path"
                                class="mt-1 block w-full max-w-xs font-mono text-sm"
                                placeholder="/"
                                :disabled="! $supportsPathPrefixes"
                            />
                            <x-input-error :messages="$errors->get('bulk_basic_auth_path')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="bulk_basic_auth_input" :value="__('Lines (user:password — one per line)')" />
                            <textarea
                                id="bulk_basic_auth_input"
                                wire:model="bulk_basic_auth_input"
                                rows="5"
                                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                placeholder="alice:hunter2&#10;bob:$apr1$abc123$ABCDEFGhijklmn0123456&#10;# blank lines and # comments are ignored"
                            ></textarea>
                            <p class="mt-1 text-xs text-brand-moss">
                                {{ __('Plaintext secrets are bcrypted on save. Lines starting with $2y$, $apr1$, $5$ or $6$ are accepted as-is. Duplicate usernames are skipped.') }}
                            </p>
                            <x-input-error :messages="$errors->get('bulk_basic_auth_input')" class="mt-1" />
                        </div>
                        <div class="flex justify-end">
                            <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="bulkImportBasicAuth">
                                <span wire:loading.remove wire:target="bulkImportBasicAuth">{{ __('Import users') }}</span>
                                <span wire:loading wire:target="bulkImportBasicAuth">{{ __('Importing…') }}</span>
                            </x-secondary-button>
                        </div>
                    </form>
                </details>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                <p class="mr-auto text-xs text-brand-moss">{{ __('Saved on the next webserver config apply.') }}</p>
                <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit" form="add-basic-auth-form" wire:loading.attr="disabled" wire:target="addBasicAuthUser">
                    <span wire:loading.remove wire:target="addBasicAuthUser">{{ __('Add credential') }}</span>
                    <span wire:loading wire:target="addBasicAuthUser">{{ __('Adding…') }}</span>
                </x-primary-button>
            </div>
        </x-modal>

        {{-- Credentials list, grouped by path. --}}
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                class="border-b border-brand-ink/10"
                icon="heroicon-o-key"
                :title="__('Credentials')"
                :note="__('Rotate or remove — applied on the next webserver config write.')"
                :count="trans_choice('{0} 0|{1} :count|[2,*] :count', $entryCount, ['count' => $entryCount])"
            />

            @if ($entryCount === 0)
                <div class="px-3 py-5 text-center sm:px-4">
                    <p class="text-sm font-medium text-brand-ink">{{ __('No credentials yet.') }}</p>
                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Add a credential above to start gating this site.') }}</p>
                </div>
            @else
                <div class="divide-y divide-brand-ink/8">
                    @foreach ($byPath->sortKeys() as $pathKey => $usersForPath)
                        @php
                            $firstUsername = $usersForPath->first()->username ?? 'user';
                            // Curl one-liner per path so an operator can verify the gate without
                            // remembering -u/-H syntax. Keep -k off — sites usually have certs.
                            $testCurl = sprintf(
                                'curl -i -u %s https://%s%s',
                                escapeshellarg($firstUsername.':PASSWORD'),
                                $primaryHost,
                                $pathKey,
                            );
                        @endphp
                        <div wire:key="ba-path-{{ md5($pathKey) }}">
                            <div class="flex flex-wrap items-center justify-between gap-2 bg-brand-sand/15 px-3 py-2 sm:px-4">
                                <div class="flex items-center gap-2 text-xs">
                                    <x-heroicon-m-folder class="h-3.5 w-3.5 text-brand-moss" />
                                    <span class="font-mono font-semibold text-brand-ink">{{ $pathKey }}</span>
                                    @if ($pathKey === '/')
                                        <span class="rounded-full bg-white px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('whole site') }}</span>
                                    @endif
                                    <span class="text-brand-mist">·</span>
                                    <span class="text-brand-moss">{{ trans_choice('{1} :count user|[2,*] :count users', $usersForPath->count(), ['count' => $usersForPath->count()]) }}</span>
                                </div>

                                {{-- Per-path test snippet: copy a curl one-liner. Keeps PASSWORD as
                                     a literal placeholder so we never put a secret in the DOM. --}}
                                <details class="ml-auto" x-data="{ copied: false }">
                                    <summary class="cursor-pointer list-none text-[11px] font-medium text-brand-sage hover:underline">
                                        <span class="inline-flex items-center gap-1">
                                            <x-heroicon-m-command-line class="h-3 w-3" />
                                            {{ __('Test') }}
                                        </span>
                                    </summary>
                                    <div class="mt-2 w-full max-w-2xl rounded-lg border border-brand-ink/10 bg-brand-cream/60 p-3">
                                        <p class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Verify with curl — replace PASSWORD with the credential') }}</p>
                                        <div class="mt-1.5 flex items-start gap-2">
                                            <code class="block flex-1 break-all font-mono text-[11px] leading-relaxed text-brand-ink">{{ $testCurl }}</code>
                                            <button
                                                type="button"
                                                class="shrink-0 rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-[10px] font-semibold text-brand-ink hover:bg-brand-sand/40"
                                                @click="navigator.clipboard.writeText(@js($testCurl)); copied = true; setTimeout(() => copied = false, 1800)"
                                            >
                                                <span x-show="!copied">{{ __('Copy') }}</span>
                                                <span x-show="copied" x-cloak class="text-emerald-700">{{ __('Copied') }}</span>
                                            </button>
                                        </div>
                                        <p class="mt-2 text-[10px] text-brand-moss">{{ __('Expect 401 without credentials and 200 with the right user/password.') }}</p>
                                    </div>
                                </details>
                            </div>

                            <ul class="divide-y divide-brand-ink/8">
                                @foreach ($usersForPath->sortBy('username') as $authUser)
                                    @php $pending = $authUser->isPendingRemoval(); @endphp
                                    <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-1.5 sm:px-4 {{ $pending ? 'opacity-60' : '' }}" wire:key="ba-user-{{ $authUser->id }}">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10">
                                                <x-heroicon-o-user-circle class="h-3.5 w-3.5" />
                                            </span>
                                            <div class="min-w-0">
                                                <p class="flex flex-wrap items-center gap-2 font-mono text-sm font-semibold text-brand-ink">
                                                    <span class="{{ $pending ? 'line-through' : '' }}">{{ $authUser->username }}</span>
                                                    @if ($pending)
                                                        <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-900 ring-1 ring-inset ring-amber-200/60">
                                                            <x-spinner variant="forest" size="sm" />
                                                            {{ __('Removing') }}
                                                        </span>
                                                    @endif
                                                    @if ($authUser->isDiscoveredFromServer())
                                                        {{-- Badge on rows imported via "Sync from server". The chip's tooltip
                                                             carries the absolute source path so the operator can spot a
                                                             surprise file (something outside .dply/basic-auth) without
                                                             scrolling through SSH output. --}}
                                                        <span
                                                            class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-sky-800 ring-1 ring-inset ring-sky-200/70"
                                                            title="{{ __('Imported from :path', ['path' => $authUser->source_file_path]) }}"
                                                        >
                                                            <x-heroicon-m-magnifying-glass class="h-3 w-3" />
                                                            {{ __('Discovered') }}
                                                        </span>
                                                    @endif
                                                    @if ($isCaddy && ! $authUser->passwordHashIsBcrypt())
                                                        {{-- Caddy v2 can only enforce bcrypt hashes inline. Imported apr1/sha
                                                             entries get this chip until the operator rotates the password
                                                             (which regenerates a bcrypt hash). --}}
                                                        <span
                                                            class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-900 ring-1 ring-inset ring-amber-200/70"
                                                            title="{{ __('Caddy can only enforce bcrypt hashes — click Rotate to regenerate this credential.') }}"
                                                        >
                                                            <x-heroicon-o-exclamation-triangle class="h-3 w-3" />
                                                            {{ __('Rotate to enforce') }}
                                                        </span>
                                                    @endif
                                                </p>
                                                <p class="mt-0.5 text-[11px] text-brand-mist">
                                                    @if ($pending)
                                                        {{ __('Marked :time — drops at the end of the running webserver apply.', ['time' => $authUser->pending_removal_at?->diffForHumans() ?? '—']) }}
                                                    @elseif ($authUser->updated_at && $authUser->updated_at->ne($authUser->created_at))
                                                        {{ __('Updated :time', ['time' => $authUser->updated_at->diffForHumans()]) }}
                                                    @else
                                                        {{ __('Added :time', ['time' => $authUser->created_at?->diffForHumans() ?? '—']) }}
                                                    @endif
                                                    @if ($authUser->isDiscoveredFromServer())
                                                        <span class="text-brand-mist/70">·</span>
                                                        <span class="font-mono text-[10px] text-brand-mist">{{ $authUser->source_file_path }}</span>
                                                    @endif
                                                </p>
                                            </div>
                                        </div>

                                        <div class="flex flex-wrap items-center gap-2">
                                            {{-- Opens the rotate dialog in `pending` state via a browser
                                                 event — no server call yet. The actual rotate fires from
                                                 the dialog's Submit button, which calls Livewire's
                                                 rotateBasicAuthPassword(). --}}
                                            <button
                                                type="button"
                                                @click="$dispatch('dply-basic-auth-password-rotate-prompt', {
                                                    user_id: @js($authUser->id),
                                                    username: @js($authUser->username),
                                                    path: @js($authUser->normalizedPath() ?: '/'),
                                                    host: @js($primaryHost),
                                                })"
                                                @disabled($pending)
                                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                                                title="{{ __('Generate a new password and reveal it once') }}"
                                            >
                                                <x-heroicon-o-arrow-path class="h-4 w-4" />
                                                {{ __('Rotate') }}
                                            </button>
                                            @if (! $pending)
                                                <button
                                                    type="button"
                                                    wire:click="confirmRemoveBasicAuthUser('{{ $authUser->id }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="confirmRemoveBasicAuthUser('{{ $authUser->id }}')"
                                                    class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40"
                                                    title="{{ __('Remove credential') }}"
                                                    aria-label="{{ __('Remove') }}"
                                                >
                                                    <x-heroicon-o-trash class="h-4 w-4" wire:loading.remove wire:target="confirmRemoveBasicAuthUser('{{ $authUser->id }}')" />
                                                    <span wire:loading wire:target="confirmRemoveBasicAuthUser('{{ $authUser->id }}')"><x-spinner variant="forest" size="sm" /></span>
                                                </button>
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        @include('livewire.sites.settings.partials.basic-auth-password-reveal-modal')
        @endif
    @endif

    <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2 sm:px-4">
        <x-cli-snippet :commands="[
            ['label' => __('List users'), 'command' => 'dply sites:basic-auth:list '.$site->slug],
            ['label' => __('Add user'), 'command' => 'dply sites:basic-auth:add '.$site->slug.' <user> <password>'],
            ['label' => __('Remove user'), 'command' => 'dply sites:basic-auth:remove '.$site->slug.' <user>'],
        ]" />
    </div>
</div>
