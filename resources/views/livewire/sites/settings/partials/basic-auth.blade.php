@php
    $card = 'dply-card overflow-hidden';
    $supportsBA = $site->supportsBasicAuthProvisioning();
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

<section class="space-y-6">
    {{-- Apply banner is rendered at the settings.blade.php top level so it's visible on
         every section, not just basic-auth. --}}

    <x-explainer tone="info">
        <p>{{ __('HTTP basic auth puts a username/password gate in front of all or part of this site. Dply hashes credentials in the database and writes htpasswd files on the server inside your repo\'s .dply/basic-auth directory; the webserver config references those files.') }}</p>
        <p>{{ __('Path scope: use / to protect the whole site, or a prefix like /wp-admin to gate just one section. Octane and Node sites only support / in this release.') }}</p>
        <p>{{ __('Dply only stores password hashes — the plaintext is shown once in this UI. Use Rotate password if you need a fresh credential without losing the entry.') }}</p>
        <p>{{ __('Sync from server scans the repository for stray .htpasswd files (Dply-written or otherwise) and imports their entries so you can remove them through this UI. Discovered rows are tagged so you can tell them apart from credentials you added here.') }}</p>
    </x-explainer>

    @if (! $supportsBA)
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-50 text-amber-900 ring-amber-200">
                        <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Basic auth unavailable on this runtime') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Basic authentication applies to VM sites with managed web server configuration. Container and serverless runtimes use their own access controls.') }}</p>
                    </div>
                </div>
            </div>
        </section>
    @else
        {{-- Slim header card: icon, title, count + freshness, and the primary CTAs.
             Inspired by the SSH keys workspace — keeps the page from being dominated by a
             big inline form when the operator just wants to add or rotate one credential. --}}
        <div class="{{ $card }}">
            <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Access') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('HTTP basic authentication') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Username and password pairs that the webserver checks before letting a request through.') }}
                            <a href="https://datatracker.ietf.org/doc/html/rfc7617" target="_blank" rel="noopener" class="whitespace-nowrap font-medium text-brand-forest hover:text-brand-sage hover:underline">{{ __('Learn more') }}</a>
                        </p>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                            <span class="inline-flex items-center gap-1">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                {{ trans_choice('{0} no credentials|{1} :count credential|[2,*] :count credentials', $entryCount, ['count' => $entryCount]) }}
                            </span>
                            @if ($protectedPaths->isNotEmpty())
                                <span class="text-brand-mist/60">·</span>
                                <span class="inline-flex items-center gap-1">
                                    <x-heroicon-m-folder class="h-3 w-3" />
                                    {{ trans_choice('{1} :count path|[2,*] :count paths', $protectedPaths->count(), ['count' => $protectedPaths->count()]) }}
                                </span>
                            @endif
                            @if ($latestUpdated)
                                <span class="text-brand-mist/60">·</span>
                                <span>{{ __('updated :time', ['time' => $latestUpdated->diffForHumans()]) }}</span>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    {{-- Pulls leftover .htpasswd files from inside the site repo back into
                         the database so they show up below and can be removed via the
                         normal flow. Useful when a previous setup (or a partial Dply apply)
                         left a gate on disk that the UI didn't know about. --}}
                    <button
                        type="button"
                        wire:click="syncBasicAuthFromServer"
                        wire:loading.attr="disabled"
                        wire:target="syncBasicAuthFromServer"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-60"
                        title="{{ __('Scan the server for .htpasswd files inside this site\'s repo and import any users we don\'t already track.') }}"
                    >
                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="syncBasicAuthFromServer" />
                        <span wire:loading wire:target="syncBasicAuthFromServer" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                            <x-spinner variant="forest" size="sm" />
                        </span>
                        <span wire:loading.remove wire:target="syncBasicAuthFromServer">{{ __('Sync from server') }}</span>
                        <span wire:loading wire:target="syncBasicAuthFromServer">{{ __('Scanning…') }}</span>
                    </button>
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'add-basic-auth-modal')"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90"
                    >
                        <x-heroicon-o-plus class="h-3.5 w-3.5" />
                        {{ __('Add credential') }}
                    </button>
                </div>
            </div>
        </div>

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

        {{-- List of credentials, grouped by path. Each row carries username + path chip,
             added/updated timestamps, and per-row Rotate / Delete actions. --}}
        <div class="{{ $card }}">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-sky-50 text-sky-700 ring-sky-200">
                        <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Library') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Credentials') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Rotate or remove credentials — applied on the next webserver config write.') }}</p>
                    </div>
                </div>
                <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full bg-brand-sand/40 px-2.5 py-1 text-[11px] font-semibold text-brand-moss">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                    {{ trans_choice('{0} no credentials|{1} :count credential|[2,*] :count credentials', $entryCount, ['count' => $entryCount]) }}
                </span>
            </div>

            @if ($entryCount === 0)
                <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss">
                        <x-heroicon-o-lock-closed class="h-6 w-6" />
                    </span>
                    <p class="text-sm font-medium text-brand-ink">{{ __('No credentials yet.') }}</p>
                    <p class="text-xs text-brand-moss">{{ __('Add a credential above to start gating this site.') }}</p>
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
                            <div class="flex flex-wrap items-center justify-between gap-2 bg-brand-sand/15 px-6 py-2.5 sm:px-8">
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
                                    <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 sm:px-8 {{ $pending ? 'opacity-60' : '' }}" wire:key="ba-user-{{ $authUser->id }}">
                                        <div class="flex min-w-0 items-center gap-3">
                                            <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sand/40 text-brand-forest ring-brand-ink/10">
                                                <x-heroicon-o-user-circle class="h-4 w-4" />
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
                                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
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
        </div>

        @include('livewire.sites.settings.partials.basic-auth-password-reveal-modal')
    @endif

    <x-cli-snippet tone="stub" />
</section>
