@php
    use App\Models\ConsoleAction;
    use App\Services\Sites\DotEnvFileParser;

    $card = 'dply-card overflow-hidden';

    // Capability gates: only VM-style hosts have a server-side .env file we can
    // sync from / push to. Container/serverless runtimes still use the same
    // encrypted cache (since there's nothing on disk to read), so the rest of
    // the UX is identical — only the Sync/Push buttons disappear.
    $supportsEnvPush = $server->hostCapabilities()->supportsEnvPushToHost();

    // Parse the encrypted cache once per render. The Livewire methods that
    // mutate keys do their own parse/write round-trip on save.
    $parsed = app(DotEnvFileParser::class)->parse((string) ($site->env_file_content ?? ''));
    $envMap = $parsed['variables'];
    $envComments = $parsed['comments'] ?? [];
    $parserErrors = $parsed['errors'];
    ksort($envMap);

    // Workspace-inherited keys (read-only on this page; managed at the project
    // level). Used both for the inherited section and to suppress the
    // "Discovered from server" badge for keys that legitimately came from
    // workspace inheritance.
    $workspaceVariables = $site->workspace?->variables ?? collect();
    $inheritedKeys = $workspaceVariables->pluck('env_key')->map(fn ($k) => (string) $k)->all();

    $cacheOrigin = (string) ($site->env_cache_origin ?? '');
    $syncedAt = $site->env_synced_at;
    $editedAt = $site->updated_at;

    // Freshness pill copy: prefer the synced timestamp when the cache came
    // from a server read; otherwise show when the operator last edited.
    if ($cacheOrigin === 'server' && $syncedAt) {
        $freshnessLabel = __('synced :time', ['time' => $syncedAt->diffForHumans()]);
    } elseif ($cacheOrigin === 'local-edit' && $editedAt) {
        $freshnessLabel = __('edited :time', ['time' => $editedAt->diffForHumans()]);
    } else {
        $freshnessLabel = null;
    }

    $variableCount = count($envMap);

    // Sync-in-flight gates wire:poll on the keys list so the page picks up
    // the cache update the moment the lazy first-visit sync (or a manual
    // Sync) finishes. Site variables IS the live view — auto-sync hydrates
    // it on first visit and auto-push keeps it pushed; no separate live
    // panel exists anymore.
    $envSyncInFlight = $supportsEnvPush
        && ConsoleAction::query()
            ->forSubject($site)
            ->ofKind('env_sync')
            ->notDismissed()
            ->inFlight()
            ->exists();

    // Surface "env file lives inside the docroot" as an inline finding so
    // the operator can one-click move it. Same condition as the doctor
    // command's drift check — they should always agree.
    $envInDocroot = false;
    if ($supportsEnvPush) {
        $envPath = $site->effectiveEnvFilePath();
        $docroot = rtrim((string) $site->effectiveDocumentRoot(), '/');
        $envInDocroot = $docroot !== '' && str_starts_with($envPath, $docroot.'/');
    }

    // Required env vars detected from the deployed code by the scanner
    // (.env.example + env() usage in app code and config/). "Missing" = a
    // required key that isn't set with a non-empty value here and isn't
    // workspace-inherited. Only VM hosts have code on disk to scan.
    $envPresentKeys = [];
    foreach ($envMap as $envK => $envV) {
        if (trim((string) $envV) !== '') {
            $envPresentKeys[] = (string) $envK;
        }
    }
    // Resource bindings inject their connection variables at deploy (DB_*,
    // REDIS_*, …), so a key a binding supplies with a value is NOT missing even
    // though it isn't in the editable .env. Count those as present. (The full
    // binding maps for the UI are built further down.)
    foreach ($site->bindings as $presentBinding) {
        foreach ($presentBinding->connectionEnv() as $bindingKey => $bindingValue) {
            if (trim((string) $bindingValue) !== '') {
                $envPresentKeys[] = (string) $bindingKey;
            }
        }
    }
    $envPresentKeys = array_values(array_unique($envPresentKeys));
    $missingEnv = $supportsEnvPush ? $site->missingRequiredEnvKeys($envPresentKeys, $inheritedKeys) : [];
    $envRequirements = $site->envRequirements();
    $envScannedAt = $envRequirements['scanned_at'] ?? null;

    // Gate opt-out state — only the deploy-hub host component (DeploymentsList)
    // carries the ignore/re-enable actions, so feature-detect them.
    $envGateOff = method_exists($this, 'envGateSkipped') && $this->envGateSkipped();
    $canIgnoreEnv = method_exists($this, 'ignoreMissingEnv');
    $ignoredEnvKeys = ($canIgnoreEnv && method_exists($this, 'ignoredEnvKeys')) ? $this->ignoredEnvKeys() : [];

    // Config sanity checks (debug-in-prod, missing APP_KEY, placeholder
    // secrets, …). Keyed by env KEY for per-row badges.
    // Validate the EFFECTIVE env (editable cache + connection variables a
    // resource binding injects at deploy), so a value supplied by an attached
    // database/redis isn't flagged as "empty". A real .env key still wins.
    $envMapForValidation = $envMap;
    foreach ($site->bindings as $validationBinding) {
        foreach ($validationBinding->connectionEnv() as $vbKey => $vbVal) {
            if (! array_key_exists((string) $vbKey, $envMapForValidation)) {
                $envMapForValidation[(string) $vbKey] = (string) $vbVal;
            }
        }
    }
    $envWarnings = app(\App\Services\Sites\SiteEnvValidator::class)->validate($envMapForValidation);
    $envWarningsByKey = [];
    foreach ($envWarnings as $w) {
        if (! empty($w['key'])) {
            $envWarningsByKey[$w['key']][] = $w;
        }
    }

    // Managed connection variables contributed by resource bindings (database,
    // redis, queue, storage). They inject at deploy time UNDER the editable
    // .env, so surface them inline instead of in a separate card: a key not yet
    // in the site .env shows as a managed row; a key also set in .env overrides
    // the binding (editing a managed row writes that override). Marker bindings
    // (scheduler/workers/publication) contribute no env and stay in the
    // Resources card below.
    $bindingProvidedKeys = [];   // KEY => ['type', 'name', 'bindingId']
    $bindingManagedEnv = [];     // KEY => ['type', 'name', 'value', 'bindingId']  (flat — used for present/empty checks)
    $bindingManagedGroups = [];  // bindingId => ['type', 'name', 'connectivity', 'vars' => [KEY => value]]
    foreach ($site->bindings as $managedBindingRow) {
        $bid = (string) $managedBindingRow->id;
        foreach ($managedBindingRow->connectionEnv() as $bKey => $bVal) {
            $bKey = (string) $bKey;
            $bindingProvidedKeys[$bKey] = [
                'type' => $managedBindingRow->type,
                'name' => (string) $managedBindingRow->name,
                'bindingId' => $bid,
            ];
            if (! array_key_exists($bKey, $envMap)) {
                $bindingManagedEnv[$bKey] = [
                    'type' => $managedBindingRow->type,
                    'name' => (string) $managedBindingRow->name,
                    'value' => (string) $bVal,
                    'bindingId' => $bid,
                ];
                if (! isset($bindingManagedGroups[$bid])) {
                    $cfg = is_array($managedBindingRow->config) ? $managedBindingRow->config : [];
                    $bindingManagedGroups[$bid] = [
                        'type' => $managedBindingRow->type,
                        'name' => (string) $managedBindingRow->name,
                        'connectivity' => $cfg['connectivity'] ?? null,
                        'vars' => [],
                    ];
                }
                $bindingManagedGroups[$bid]['vars'][$bKey] = (string) $bVal;
            }
        }
    }
    ksort($bindingManagedEnv);
    foreach ($bindingManagedGroups as &$grp) {
        ksort($grp['vars']);
    }
    unset($grp);
    $bindingTypeLabelsInline = [
        'database' => __('Database'),
        'redis' => __('Redis'),
        'queue' => __('Queue'),
        'storage' => __('Object storage'),
    ];

    // Live search over key names + auto-derived prefix groups (APP, DB, AWS,
    // MAIL, REDIS, …) for one-click filtering.
    $envSearchTerm = strtolower(trim((string) ($env_search ?? '')));
    $selectedEnvGroup = trim((string) ($env_group ?? ''));
    $groupOfKey = function (string $k): string {
        $p = explode('_', $k, 2);
        return count($p) > 1 && $p[0] !== '' ? $p[0] : 'MISC';
    };
    $envGroups = [];
    foreach (array_keys($envMap) as $gk) {
        $g = $groupOfKey((string) $gk);
        $envGroups[$g] = ($envGroups[$g] ?? 0) + 1;
    }
    ksort($envGroups);

    $filteredEnvMap = $envMap;
    if ($envSearchTerm !== '') {
        $filteredEnvMap = array_filter($filteredEnvMap, fn ($v, $k) => str_contains(strtolower((string) $k), $envSearchTerm), ARRAY_FILTER_USE_BOTH);
    }
    if ($selectedEnvGroup !== '') {
        $filteredEnvMap = array_filter($filteredEnvMap, fn ($v, $k) => $groupOfKey((string) $k) === $selectedEnvGroup, ARRAY_FILTER_USE_BOTH);
    }

    // The richer controls (search, group chips, edit-all, pagination) live on
    // the deploy-hub component's trait. Settings (Show) renders this same
    // partial without them, so gate on a trait-only method to avoid binding to
    // properties/methods that don't exist there.
    $envAdvanced = method_exists($this, 'openEditAllEnv');

    // In-memory pagination of the filtered list.
    $envPerPage = 25;
    $envFilteredCount = count($filteredEnvMap);
    $envTotalPages = max(1, (int) ceil($envFilteredCount / $envPerPage));
    $envCurrentPage = $envAdvanced ? min(max(1, (int) ($env_page ?? 1)), $envTotalPages) : 1;
    $listEnvMap = $envAdvanced
        ? array_slice($filteredEnvMap, ($envCurrentPage - 1) * $envPerPage, $envPerPage, true)
        : $filteredEnvMap;

    // Console-action banner feed (sync / push / scan progress). The Settings
    // page mounts its own banner at the top level, so we only render one here
    // in the deploy hub (detected by the absence of $sectionConsoleActionKinds).
    $envConsoleRun = \App\Models\ConsoleAction::query()
        ->forSubject($site)
        ->whereIn('kind', ['env_sync', 'env_push', 'env_scan', 'binding_validate', 'site_test', 'site_remediate'])
        ->notDismissed()
        ->orderByDesc('created_at')
        ->first();
@endphp

<section
    class="space-y-6"
    @if ($supportsEnvPush && empty($envMap) && $cacheOrigin === '' && ! $envSyncInFlight)
        wire:init="autoSyncIfFirstVisit"
    @endif
>
    {{-- Configuration check — surfaced at the very top so risky settings
         (debug-in-prod, empty APP_KEY, plaintext URLs, placeholder secrets)
         are the first thing you see and can jump straight to fixing. Each
         keyed warning filters the list to that variable on click. --}}
    @if ($envWarnings !== [])
        @php $hasDanger = collect($envWarnings)->contains(fn ($w) => $w['level'] === 'danger'); @endphp
        <div class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 {{ $hasDanger ? 'bg-rose-50' : 'bg-amber-50' }} px-5 py-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $hasDanger ? 'bg-rose-100 text-rose-700 ring-rose-200' : 'bg-amber-100 text-amber-700 ring-amber-200' }}">
                    <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] {{ $hasDanger ? 'text-rose-700' : 'text-amber-700' }}">{{ __('Configuration check') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold {{ $hasDanger ? 'text-rose-900' : 'text-amber-950' }}">
                        {{ trans_choice('{1} :count configuration warning|[2,*] :count configuration warnings', count($envWarnings), ['count' => count($envWarnings)]) }}
                    </h3>
                    <ul class="mt-1.5 space-y-1.5">
                        @foreach ($envWarnings as $w)
                            <li class="flex items-start justify-between gap-3 text-sm {{ $w['level'] === 'danger' ? 'text-rose-800' : ($w['level'] === 'warn' ? 'text-amber-900' : 'text-brand-moss') }}">
                                <span class="flex items-start gap-2">
                                    <span class="mt-1.5 inline-block h-1.5 w-1.5 shrink-0 rounded-full {{ $w['level'] === 'danger' ? 'bg-rose-600' : ($w['level'] === 'warn' ? 'bg-amber-500' : 'bg-brand-mist') }}"></span>
                                    <span>{{ $w['message'] }}</span>
                                </span>
                                @if (! empty($w['key']) && $envAdvanced)
                                    <button type="button" wire:click="openFixEnvVar(@js($w['key']))" class="shrink-0 whitespace-nowrap rounded-md border border-black/10 bg-white/60 px-2 py-0.5 text-[11px] font-semibold underline-offset-2 hover:bg-white hover:underline" title="{{ __('Fix :key', ['key' => $w['key']]) }}">
                                        {{ __('Fix :key', ['key' => $w['key']]) }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- Suggested fixes — one-click remediations the last "Test site" run
         detected from the deployed app's error (e.g. a missing table → Run
         migrations). Persisted on the site so they survive a page load. --}}
    @php
        $healthRemediations = data_get($site->meta, 'health.remediations', []);
        $healthRemediations = is_array($healthRemediations) ? $healthRemediations : [];
    @endphp
    @if ($healthRemediations !== [] && method_exists($this, 'runRemediation'))
        <div class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 bg-amber-50 px-5 py-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-100 text-amber-700 ring-amber-200">
                    <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Suggested fixes') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-amber-950">
                        {{ trans_choice('{1} :count one-click fix from the last site test|[2,*] :count one-click fixes from the last site test', count($healthRemediations), ['count' => count($healthRemediations)]) }}
                    </h3>
                    <ul class="mt-2 space-y-2">
                        @foreach ($healthRemediations as $rem)
                            <li class="flex flex-wrap items-center justify-between gap-2">
                                <span class="flex min-w-0 items-start gap-2 text-sm text-amber-900">
                                    <span class="mt-1.5 inline-block h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"></span>
                                    <span>{{ $rem['reason'] ?? '' }}</span>
                                </span>
                                <button
                                    type="button"
                                    wire:click="runRemediation(@js($rem['key']))"
                                    wire:loading.attr="disabled"
                                    wire:target="runRemediation"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-amber-700 disabled:opacity-60"
                                >
                                    <x-heroicon-o-play class="h-3.5 w-3.5" />
                                    {{ $rem['label'] ?? __('Run fix') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- wire:init above lazy-fires the first-visit sync once after the page
         renders. The conditions ensure it only runs when truly necessary —
         empty cache, no recorded origin, no in-flight job. The sync banner
         shows progress at the top of the page; the keys list re-renders
         when the job completes (see wire:poll below). --}}
    {{-- On the Settings page (section === 'environment') the console-action
         banner is mounted at the top level. In the deploy hub the view runs
         under section 'deploy' and renders no banner, so mount one here for the
         env run. Guarding on $section avoids a double banner on Settings. --}}
    @if (($section ?? '') !== 'environment')
        @if ($envConsoleRun)
            <div
                id="site-console-action-banner"
                x-data="{}"
                x-on:dply-console-action-focus.window="$nextTick(() => { const el = document.getElementById('site-console-action-banner'); if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' }); })"
            >
                @include('livewire.partials.console-action-banner-static', [
                    'run' => $envConsoleRun,
                    'kindLabels' => (array) config('console_actions.kinds', []),
                ])
            </div>
        @endif
    @endif

    <x-explainer tone="info">
        <p>{{ __('Environment variables are written into the site\'s `.env` file on the server. Dply keeps an encrypted cache of the file so this page renders without an SSH round-trip.') }}</p>
        <p>{{ __('Workflow: paste a block or edit single keys — every change auto-pushes to the server, no manual save needed. Click Sync from server to pull drift caused by out-of-band edits.') }}</p>
        <p>{{ __('For runtimes without a server file (Docker, Kubernetes, Serverless), the cache IS the source of truth — the deploy job injects values when packaging the runtime.') }}</p>
        <p>
            <span class="font-semibold">{{ __('Browser exposure:') }}</span>
            {{ __('Dply\'s managed webserver config (Nginx, Apache, Caddy, OpenLiteSpeed) denies any HTTP request whose path starts with a dot — so /.env returns 403 even though the file lives in the docroot. /.well-known/ stays allowed for ACME challenges.') }}
            {{ __('For belt-and-suspenders defense, expand the Advanced disclosure below to relocate the file outside the docroot (e.g. /etc/dply/<slug>.env).') }}
        </p>
    </x-explainer>

    @if ($envInDocroot)
        <div class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 bg-amber-50 px-5 py-4">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-amber-100 text-amber-700 ring-amber-200">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">{{ __('Exposure') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-amber-950">{{ __('Env file lives inside the docroot') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-amber-900">
                            {{ __(':path is reachable by the webserver. The default deny rule blocks /.env over HTTP, but moving the file outside the docroot is safer if the rule is ever changed or bypassed.', ['path' => $site->effectiveEnvFilePath()]) }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    wire:click="relocateEnvOutsideDocroot"
                    wire:loading.attr="disabled"
                    wire:target="relocateEnvOutsideDocroot"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 shadow-sm hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60"
                    title="{{ __('Move .env to /etc/dply/:slug.env (root:site-user 640) and push.', ['slug' => $site->slug]) }}"
                >
                    <x-heroicon-o-arrow-up-on-square class="h-3.5 w-3.5" />
                    {{ __('Move outside docroot') }}
                </button>
            </div>
        </div>
    @endif

    @if ($parserErrors !== [])
        <div class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 bg-rose-50 px-5 py-4">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-rose-100 text-rose-700 ring-rose-200">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Parse error') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-rose-900">{{ __('The cached .env has parse errors') }}</h3>
                    <ul class="mt-1 list-inside list-disc text-sm text-rose-800">
                        @foreach ($parserErrors as $err)
                            <li class="font-mono text-xs">{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    {{-- Missing required env warning. Driven by the scanner's detected
         requirements (refreshed each deploy; re-scan on demand). Lists the
         keys the deployed code expects but that aren't set here, with a
         one-click modal to add them. --}}
    @if ($supportsEnvPush && $envAdvanced && $missingEnv !== [] && ! $envGateOff)
        <div class="dply-card overflow-hidden">
            <div class="flex flex-col gap-3 bg-rose-50 px-5 py-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-rose-100 text-rose-700 ring-rose-200">
                            <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Missing variables') }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-rose-900">
                                {{ trans_choice('{1} :count required variable is missing|[2,*] :count required variables are missing', count($missingEnv), ['count' => count($missingEnv)]) }}
                            </h3>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-rose-900">
                                {{ __('These are referenced by the deployed code (.env.example, plus env() usage in app code and config/) but aren\'t set here. The app may error until they have values.') }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach (array_slice($missingEnv, 0, 24) as $entry)
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 font-mono text-[11px] font-semibold text-rose-800 ring-1 ring-inset ring-rose-200"
                                        title="{{ __('source: :s', ['s' => implode(', ', $entry['sources'])]) }}"
                                    >
                                        {{ $entry['key'] }}
                                        @if ($canIgnoreEnv)
                                            <button type="button" wire:click="confirmIgnoreEnvKey('{{ $entry['key'] }}')" class="-mr-0.5 text-rose-400 hover:text-rose-700" title="{{ __('Ignore :key', ['key' => $entry['key']]) }}" aria-label="{{ __('Ignore :key', ['key' => $entry['key']]) }}">
                                                <x-heroicon-o-x-mark class="h-3 w-3" />
                                            </button>
                                        @endif
                                    </span>
                                @endforeach
                                @if (count($missingEnv) > 24)
                                    <span class="inline-flex items-center rounded-full bg-rose-100 px-2 py-0.5 text-[11px] font-semibold text-rose-800">
                                        {{ __('+:count more', ['count' => count($missingEnv) - 24]) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-nowrap items-center gap-2 whitespace-nowrap">
                        <button
                            type="button"
                            wire:click="rescanEnvRequirements"
                            wire:loading.attr="disabled"
                            wire:target="rescanEnvRequirements"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-60"
                            title="{{ __('Re-scan the deployed code for required variables.') }}"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="rescanEnvRequirements" />
                            <span wire:loading wire:target="rescanEnvRequirements" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                            {{ __('Re-scan') }}
                        </button>
                        <button
                            type="button"
                            wire:click="openMissingEnvModal"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-rose-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-rose-800"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('Add missing variables') }}
                        </button>
                        @if ($canIgnoreEnv)
                            <button
                                type="button"
                                wire:click="confirmIgnoreMissingEnv"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                title="{{ __('Stop warning/blocking on missing required variables for this site.') }}"
                            >
                                <x-heroicon-o-no-symbol class="h-3.5 w-3.5" />
                                {{ __('Ignore all') }}
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Required-env checks are off for this site (operator chose to ignore
         missing vars). Muted reminder with a one-click re-enable. --}}
    @if ($supportsEnvPush && $envGateOff)
        <div class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-sm text-brand-moss">
            <span class="inline-flex items-center gap-2">
                <x-heroicon-o-no-symbol class="h-4 w-4 text-brand-mist" />
                {{ __('Required-variable checks are off for this site — deploys won\'t be blocked by missing env.') }}
            </span>
            <button type="button" wire:click="enableEnvGate" class="font-semibold text-brand-forest hover:underline">
                {{ __('Re-enable') }}
            </button>
        </div>
    @endif

    {{-- Add modal: single-row form on top, bulk-paste disclosure underneath.
         Mirrors basic-auth's add modal pattern. --}}
    <x-modal name="add-env-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Environment variable') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add a variable') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Add a single KEY=value pair. To import many at once, use the Paste .env button instead.') }}
            </p>
            {{-- Top-right close. Mirrors the Cancel button at the bottom but
                 is always visible, so the operator can dismiss without
                 scrolling through a long bulk-paste block. --}}
            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                aria-label="{{ __('Close') }}"
                title="{{ __('Close') }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="px-6 py-6">
            <form wire:submit="addEnvVar" id="add-env-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div class="sm:col-span-1">
                        <x-input-label for="new_env_key" :value="__('Key')" />
                        <x-text-input
                            id="new_env_key"
                            wire:model="new_env_key"
                            class="mt-1 block w-full font-mono text-sm"
                            autocomplete="off"
                            placeholder="APP_DEBUG"
                        />
                        <x-input-error :messages="$errors->get('new_env_key')" class="mt-1" />
                    </div>
                    <div class="sm:col-span-2"
                        x-data="{
                            showValue: false,
                            async copyValue() {
                                const v = document.getElementById('new_env_value')?.value || '';
                                if (!v) return;
                                try { await navigator.clipboard.writeText(v); } catch (e) {}
                            },
                        }"
                    >
                        <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="new_env_value">
                            <span>{{ __('Value') }}</span>
                            <span class="flex items-center gap-3 text-xs">
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="copyValue()">
                                    {{ __('Copy') }}
                                </button>
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="showValue = !showValue">
                                    <span x-show="!showValue">{{ __('Show') }}</span>
                                    <span x-show="showValue" x-cloak>{{ __('Hide') }}</span>
                                </button>
                            </span>
                        </label>
                        <input
                            id="new_env_value"
                            wire:model="new_env_value"
                            x-bind:type="showValue ? 'text' : 'password'"
                            autocomplete="off"
                            spellcheck="false"
                            class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                        />
                        <x-input-error :messages="$errors->get('new_env_value')" class="mt-1" />
                    </div>
                </div>
                {{-- Optional comment that renders as a `# ...` line above the
                     KEY=value in the .env file. Useful for "what is this for?"
                     reminders that survive into deploys. Multi-line comments
                     emit one `#` line each. --}}
                <div>
                    <x-input-label for="new_env_comment" :value="__('Comment (optional)')" />
                    <textarea
                        id="new_env_comment"
                        wire:model="new_env_comment"
                        rows="2"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        placeholder="{{ __('e.g. Stripe webhook signing secret — rotate quarterly') }}"
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('Rendered as a # comment line above this variable in the .env file.') }}
                    </p>
                    <x-input-error :messages="$errors->get('new_env_comment')" class="mt-1" />
                </div>
            </form>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">
                @if ($supportsEnvPush)
                    {{ __('Saved and auto-pushed to the server.') }}
                @else
                    {{ __('Saved. Values are injected on the next deploy.') }}
                @endif
            </p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="add-env-form" wire:loading.attr="disabled" wire:target="addEnvVar">
                <span wire:loading.remove wire:target="addEnvVar">{{ __('Add variable') }}</span>
                <span wire:loading wire:target="addEnvVar">{{ __('Adding…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    {{-- Paste .env: first-class bulk import. Paste a whole .env block and it
         merges into the existing cache — keys not in the paste are preserved,
         pasted keys overwrite. Closes on success (bulkImportEnvVars dispatches
         close-modal) so the operator drops back to the updated list. --}}
    <x-modal name="paste-env-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Environment') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Paste a .env') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Paste a multi-line .env block — one KEY=value per line. Existing keys you don\'t paste are preserved; pasted keys overwrite matching values.') }}
            </p>
            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                aria-label="{{ __('Close') }}"
                title="{{ __('Close') }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="px-6 py-6">
            <form wire:submit="bulkImportEnvVars" id="paste-env-form" class="space-y-3">
                <div>
                    <x-input-label for="paste_env_input" :value="__('.env contents')" />
                    <textarea
                        id="paste_env_input"
                        wire:model="bulk_env_input"
                        rows="14"
                        autocomplete="off"
                        spellcheck="false"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        placeholder="# Database settings&#10;DB_PASSWORD=hunter2&#10;&#10;APP_NAME=&quot;My App&quot;&#10;export AWS_REGION=us-east-1"
                    ></textarea>
                    <p class="mt-1 text-xs text-brand-moss">
                        {{ __('# comment lines directly above a KEY=value are kept as that variable\'s comment; free-floating comments and blank lines are dropped.') }}
                    </p>
                    <x-input-error :messages="$errors->get('bulk_env_input')" class="mt-1" />
                </div>
            </form>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">
                @if ($supportsEnvPush)
                    {{ __('Imported keys auto-push to the server.') }}
                @else
                    {{ __('Imported keys are injected on the next deploy.') }}
                @endif
            </p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="paste-env-form" wire:loading.attr="disabled" wire:target="bulkImportEnvVars">
                <span wire:loading.remove wire:target="bulkImportEnvVars">{{ __('Import variables') }}</span>
                <span wire:loading wire:target="bulkImportEnvVars">{{ __('Importing…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    {{-- "Add missing variables" modal: one input per still-missing required
         key, pre-seeded by openMissingEnvModal() with the .env.example sample
         value. Blank inputs are skipped on submit (addMissingEnvVars). --}}
    <x-modal name="add-missing-env-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-rose-700">{{ __('Missing variables') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add the required variables') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Detected from the deployed code but not set on this site. Fill in the ones you have — blanks are skipped. Saved to the Environment section and auto-pushed to the server.') }}
            </p>
            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                aria-label="{{ __('Close') }}"
                title="{{ __('Close') }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="max-h-[60vh] overflow-y-auto px-6 py-6">
            <form wire:submit="addMissingEnvVars" id="add-missing-env-form" class="space-y-3">
                @forelse ($missingEnv as $entry)
                    <div wire:key="missing-env-{{ md5($entry['key']) }}">
                        <label class="block font-mono text-xs font-semibold text-brand-ink" for="missing_env_{{ md5($entry['key']) }}">{{ $entry['key'] }}</label>
                        <input
                            id="missing_env_{{ md5($entry['key']) }}"
                            wire:model="missing_env_values.{{ $entry['key'] }}"
                            autocomplete="off"
                            spellcheck="false"
                            class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                            placeholder="{{ $entry['example'] !== null && $entry['example'] !== '' ? $entry['example'] : __('value') }}"
                        />
                        <div class="mt-0.5 flex items-center justify-between gap-2">
                            <p class="text-[11px] text-brand-mist">{{ __('source: :s', ['s' => implode(', ', $entry['sources'])]) }}</p>
                            <div class="flex items-center gap-3">
                                @if ($entry['key'] === 'APP_KEY')
                                    <button type="button" wire:click="generateMissingAppKey" class="inline-flex items-center gap-1 text-[11px] font-semibold text-brand-forest hover:underline">
                                        <x-heroicon-o-sparkles class="h-3 w-3" />
                                        {{ __('Generate a key') }}
                                    </button>
                                @endif
                                @if ($canIgnoreEnv)
                                    <button type="button" wire:click="confirmIgnoreEnvKey('{{ $entry['key'] }}')" class="text-[11px] font-semibold text-brand-mist hover:text-rose-700 hover:underline" title="{{ __('Mark this variable as intentionally unset.') }}">{{ __('Ignore this') }}</button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-brand-moss">{{ __('Nothing missing — all required variables are set.') }}</p>
                @endforelse
            </form>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">{{ __('Saved and auto-pushed to the server.') }}</p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="add-missing-env-form" wire:loading.attr="disabled" wire:target="addMissingEnvVars">
                <span wire:loading.remove wire:target="addMissingEnvVars">{{ __('Add variables') }}</span>
                <span wire:loading wire:target="addMissingEnvVars">{{ __('Adding…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    {{-- Workspace-inherited preview. Read-only here; managed at the project
         level. Placed above the per-key list so operators see what they can
         override before scanning the cache. --}}
    @if ($workspaceVariables->isNotEmpty())
        <details class="{{ $card }}">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-link class="h-4 w-4 text-brand-moss" />
                    <span class="text-sm font-semibold text-brand-ink">{{ __('Inherited from project workspace') }}</span>
                    <span class="rounded-full bg-brand-sand/40 px-2 py-0.5 text-[11px] font-semibold text-brand-moss">
                        {{ trans_choice('{1} :count variable|[2,*] :count variables', $workspaceVariables->count(), ['count' => $workspaceVariables->count()]) }}
                    </span>
                </div>
                <span class="text-[11px] text-brand-mist">{{ __('Click to expand') }}</span>
            </summary>
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($workspaceVariables->sortBy('env_key') as $wsVar)
                    <li class="flex items-center justify-between gap-3 px-6 py-2.5 sm:px-8" wire:key="ws-var-{{ $wsVar->id }}">
                        <span class="font-mono text-sm text-brand-ink">{{ $wsVar->env_key }}</span>
                        <span class="text-[11px] text-brand-mist">
                            @if ((bool) ($wsVar->is_secret ?? false))
                                {{ __('Secret — managed in project settings') }}
                            @else
                                {{ __('Project-managed — override by adding the same key here') }}
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

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
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Configuration') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Environment variables') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        @if ($supportsEnvPush)
                            {{ __('Key/value pairs written into the site\'s .env file. Edits push to the server automatically.') }}
                        @else
                            {{ __('Key/value pairs injected into the runtime on the next deploy.') }}
                        @endif
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ trans_choice('{0} no variables|{1} :count variable|[2,*] :count variables', $variableCount, ['count' => $variableCount]) }}
                        </span>
                        @if ($workspaceVariables->isNotEmpty())
                            <span class="text-brand-mist/60">·</span>
                            <span class="inline-flex items-center gap-1"><x-heroicon-m-link class="h-3 w-3" />{{ trans_choice('{1} :count inherited|[2,*] :count inherited', $workspaceVariables->count(), ['count' => $workspaceVariables->count()]) }}</span>
                        @endif
                        @if ($freshnessLabel)
                            <span class="text-brand-mist/60">·</span>
                            <span>{{ $freshnessLabel }}</span>
                        @endif
                    </div>
                </div>
            </div>
            {{-- Action toolbar: create actions on the left, the primary CTA
                 anchored right, and the occasional server / bulk-edit tools
                 tucked into a "More" menu so the bar stays tidy as it grows. --}}
            <div class="flex flex-wrap items-center gap-2 border-t border-brand-ink/10 pt-4">
                @if (method_exists($this, 'openBindingModal'))
                    {{-- Connect a managed resource (database, redis, queue,
                         storage); its connection variables then surface inline
                         in the list below as managed rows. --}}
                    <div x-data="{ open: false }" class="relative">
                        <button
                            type="button"
                            x-on:click="open = ! open"
                            x-on:click.outside="open = false"
                            wire:loading.attr="disabled"
                            wire:target="openBindingModal"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <x-heroicon-o-link class="h-3.5 w-3.5" wire:loading.remove wire:target="openBindingModal" />
                            <span wire:loading wire:target="openBindingModal" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                            <span wire:loading.remove wire:target="openBindingModal">{{ __('Connect resource') }}</span>
                            <span wire:loading wire:target="openBindingModal">{{ __('Loading…') }}</span>
                            <x-heroicon-m-chevron-down class="h-3.5 w-3.5 text-brand-mist" wire:loading.remove wire:target="openBindingModal" />
                        </button>
                        <div
                            x-show="open"
                            x-cloak
                            x-transition
                            class="absolute left-0 z-20 mt-1 w-56 overflow-hidden rounded-xl border border-brand-ink/10 bg-white py-1 shadow-lg"
                        >
                            <button type="button" wire:click="openBindingModal('database', 'attach')" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-circle-stack class="h-4 w-4 text-brand-moss" /> {{ __('Link a database') }}
                            </button>
                            <button type="button" wire:click="openBindingModal('database', 'provision')" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-plus class="h-4 w-4 text-brand-moss" /> {{ __('Provision a database') }}
                            </button>
                            <button type="button" wire:click="openBindingModal('redis', 'attach')" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-bolt class="h-4 w-4 text-brand-moss" /> {{ __('Connect Redis') }}
                            </button>
                            <button type="button" wire:click="openBindingModal('queue', 'attach')" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-queue-list class="h-4 w-4 text-brand-moss" /> {{ __('Configure queue') }}
                            </button>
                            <button type="button" wire:click="openBindingModal('storage', 'attach')" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-archive-box class="h-4 w-4 text-brand-moss" /> {{ __('Connect object storage') }}
                            </button>
                        </div>
                    </div>
                @endif

                @if (method_exists($this, 'testSiteLoads'))
                    {{-- End-to-end check: actually request the site and report
                         whether it loads, pulling the server error on failure. --}}
                    <button
                        type="button"
                        wire:click="testSiteLoads"
                        wire:loading.attr="disabled"
                        wire:target="testSiteLoads"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-forest/30 bg-brand-forest/5 px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm transition-colors hover:bg-brand-forest/10 disabled:opacity-60"
                        title="{{ __('Request the live site and confirm it loads (HTTP check + server log on failure).') }}"
                    >
                        <x-heroicon-o-beaker class="h-3.5 w-3.5" wire:loading.remove wire:target="testSiteLoads" />
                        <span wire:loading wire:target="testSiteLoads" class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner variant="forest" size="sm" /></span>
                        <span wire:loading.remove wire:target="testSiteLoads">{{ __('Test site') }}</span>
                        <span wire:loading wire:target="testSiteLoads">{{ __('Testing…') }}</span>
                    </button>
                @endif

                {{-- Overflow: occasional server-sync + bulk-edit tools. --}}
                <div x-data="{ open: false }" class="relative">
                    <button
                        type="button"
                        x-on:click="open = ! open"
                        x-on:click.outside="open = false"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    >
                        <x-heroicon-m-ellipsis-horizontal class="h-4 w-4 text-brand-mist" />
                        {{ __('More') }}
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 text-brand-mist" />
                    </button>
                    <div
                        x-show="open"
                        x-cloak
                        x-transition
                        class="absolute left-0 z-20 mt-1 w-60 overflow-hidden rounded-xl border border-brand-ink/10 bg-white py-1 shadow-lg"
                    >
                        @if ($supportsEnvPush && method_exists($this, 'pushEnvToServer'))
                            <button type="button" wire:click="pushEnvToServer" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40" title="{{ __('Write these variables (including connected resources) to the server\'s .env now.') }}">
                                <x-heroicon-o-arrow-up-tray class="h-4 w-4 text-brand-forest" /> {{ __('Push to server') }}
                            </button>
                        @endif
                        @if ($supportsEnvPush)
                            <button type="button" wire:click="openConfirmActionModal('syncEnvFromServer', [], @js(__('Sync from server?')), @js(__('This replaces the cached variables with the live .env on the server. Any local edits here that haven\'t been pushed — and connection variables injected by attached resources (managed databases, caches) — will be overwritten with the server copy.')), @js(__('Overwrite with server copy')), true)" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-arrow-down-tray class="h-4 w-4 text-brand-moss" /> {{ __('Sync from server') }}
                            </button>
                        @endif
                        <button type="button" x-on:click="$dispatch('open-modal', 'paste-env-modal'); open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                            <x-heroicon-o-document-text class="h-4 w-4 text-brand-moss" /> {{ __('Paste .env') }}
                        </button>
                        @if ($envAdvanced)
                            <button type="button" wire:click="openEditAllEnv" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-pencil-square class="h-4 w-4 text-brand-moss" /> {{ __('View / edit all') }}
                            </button>
                        @elseif ($variableCount > 0)
                            <button type="button" x-on:click="$dispatch('open-modal', 'view-all-env-modal'); open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
                                <x-heroicon-o-document-text class="h-4 w-4 text-brand-moss" /> {{ __('View all') }}
                            </button>
                        @endif
                        @if (method_exists($this, 'runRemediation'))
                            <div class="my-1 border-t border-brand-ink/10"></div>
                            <button type="button" wire:click="runRemediation('migrate')" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40" title="{{ __('php artisan migrate --force on the server') }}">
                                <x-heroicon-o-circle-stack class="h-4 w-4 text-brand-moss" /> {{ __('Run migrations') }}
                            </button>
                            <button type="button" wire:click="runRemediation('optimize_clear')" x-on:click="open = false" class="flex w-full items-center gap-2 px-3 py-2 text-left text-xs font-semibold text-brand-ink hover:bg-brand-sand/40" title="{{ __('php artisan optimize:clear on the server') }}">
                                <x-heroicon-o-sparkles class="h-4 w-4 text-brand-moss" /> {{ __('Clear all caches') }}
                            </button>
                        @endif
                    </div>
                </div>

                <button
                    type="button"
                    x-on:click="$dispatch('open-modal', 'add-env-modal')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90 sm:ml-auto"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Add variable') }}
                </button>
            </div>
        </div>

        @if ($variableCount > 0 && $envAdvanced)
            <div class="space-y-2 border-b border-brand-ink/10 bg-white px-6 py-3 sm:px-7">
                <div class="relative">
                    <x-heroicon-o-magnifying-glass class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-brand-mist" />
                    <input
                        type="search"
                        wire:model.live.debounce.200ms="env_search"
                        placeholder="{{ __('Search variables…') }}"
                        class="block w-full rounded-lg border border-brand-ink/15 bg-brand-cream/40 py-2 pl-9 pr-3 font-mono text-sm text-brand-ink focus:border-brand-sage focus:ring-brand-sage/30"
                    />
                </div>
                @if (count($envGroups) > 1)
                    {{-- Auto-derived prefix groups (APP_, DB_, AWS_, …). Click to
                         filter the list to that group; combines with search. --}}
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" wire:click="$set('env_group', '')" @class([
                            'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold transition-colors',
                            'bg-brand-forest text-brand-cream' => $selectedEnvGroup === '',
                            'bg-brand-sand/40 text-brand-moss hover:bg-brand-sand/60' => $selectedEnvGroup !== '',
                        ])>
                            {{ __('All') }} <span class="opacity-60">{{ $variableCount }}</span>
                        </button>
                        @foreach ($envGroups as $g => $cnt)
                            <button type="button" wire:click="$set('env_group', @js($g))" @class([
                                'inline-flex items-center gap-1 rounded-full px-2.5 py-1 font-mono text-[11px] font-semibold transition-colors',
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
        {{-- Connection variables provided by attached resource bindings, grouped
             by the resource that supplies them. Each group header carries the
             resource identity + whole-binding actions (Update re-opens the
             picker to re-point/refresh; Detach removes it); the rows beneath are
             the individual variables, each overridable. --}}
        @if ($bindingManagedGroups !== [])
            <div class="border-b border-brand-ink/10 bg-sky-50/20">
                <div class="flex items-center gap-2 px-6 py-2.5 sm:px-8">
                    <x-heroicon-o-link class="h-3.5 w-3.5 text-sky-700" aria-hidden="true" />
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-sky-800">{{ __('Managed by connected resources') }}</p>
                    <span class="text-[11px] text-brand-moss">{{ __('injected at deploy · editable as an override') }}</span>
                </div>

                @foreach ($bindingManagedGroups as $gBindingId => $group)
                    @php
                        $gTypeLabel = $bindingTypeLabelsInline[$group['type']] ?? (string) str($group['type'])->title();
                        $gConn = is_array($group['connectivity'] ?? null) ? $group['connectivity'] : null;
                        $gManageable = in_array($group['type'], ['database', 'redis', 'queue', 'storage'], true);
                        // Start expanded only when a variable in this group is mid-override,
                        // so the inline editor isn't hidden behind a collapsed header.
                        $gHasEditing = ($editing_env_key ?? null) !== null && array_key_exists((string) $editing_env_key, $group['vars']);
                    @endphp
                    <div class="border-t border-sky-200/40" wire:key="managed-group-{{ md5($gBindingId) }}" x-data="{ expanded: @js($gHasEditing) }">
                        <div class="flex flex-wrap items-center justify-between gap-2 bg-sky-50/60 px-6 py-2.5 sm:px-8">
                            <button type="button" x-on:click="expanded = ! expanded" class="flex min-w-0 flex-1 items-center gap-2 text-left">
                                <x-heroicon-m-chevron-right class="h-4 w-4 shrink-0 text-brand-mist transition-transform" x-bind:class="expanded && 'rotate-90'" />
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-sky-100 text-sky-700 ring-1 ring-inset ring-sky-200/70">
                                    <x-heroicon-o-link class="h-3.5 w-3.5" />
                                </span>
                                <span class="text-sm font-semibold text-brand-ink">{{ $gTypeLabel }}</span>
                                @if ($group['name'])
                                    <span class="truncate font-mono text-xs text-brand-moss">· {{ $group['name'] }}</span>
                                @endif
                                <span class="shrink-0 rounded-full bg-white px-1.5 py-0.5 text-[10px] font-semibold text-brand-moss ring-1 ring-inset ring-brand-ink/10">{{ trans_choice('{1} :count var|[2,*] :count vars', count($group['vars']), ['count' => count($group['vars'])]) }}</span>
                                @if ($gConn !== null && ($gConn['ok'] ?? null) === true)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-800 ring-1 ring-inset ring-emerald-200/70"><x-heroicon-m-check class="h-3 w-3" />{{ __('Reachable') }}</span>
                                @elseif ($gConn !== null && ($gConn['ok'] ?? null) === false)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-rose-800 ring-1 ring-inset ring-rose-200/70" title="{{ $gConn['detail'] ?? '' }}"><x-heroicon-m-exclamation-triangle class="h-3 w-3" />{{ __('Unreachable') }}</span>
                                @endif
                            </button>
                            <div class="flex shrink-0 items-center gap-1.5">
                                @if ($gManageable && method_exists($this, 'openBindingModal'))
                                    <button type="button" wire:click="openBindingModal('{{ $group['type'] }}', 'attach')" wire:loading.attr="disabled" wire:target="openBindingModal" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-60" title="{{ __('Re-point or refresh this resource (re-pulls its current connection values).') }}">
                                        <x-heroicon-o-arrow-path class="h-3 w-3" />
                                        {{ __('Update') }}
                                    </button>
                                @endif
                                @if (method_exists($this, 'detachBinding'))
                                    <button type="button" wire:click="detachBinding(@js((string) $gBindingId))" wire:confirm="{{ __('Detach the :type binding? Its variables stop being injected at deploy.', ['type' => $gTypeLabel]) }}" class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-moss hover:bg-rose-50 hover:text-rose-700" title="{{ __('Detach binding') }}">
                                        <x-heroicon-o-x-mark class="h-3 w-3" />
                                        {{ __('Detach') }}
                                    </button>
                                @endif
                            </div>
                        </div>

                        <ul class="divide-y divide-brand-ink/8" x-show="expanded" x-cloak>
                            @foreach ($group['vars'] as $mKey => $mValue)
                                @php
                                    $mEditing = ($editing_env_key ?? null) === $mKey;
                                    $mSensitive = (bool) preg_match('/(PASSWORD|SECRET|TOKEN|KEY|URL|DSN)/i', (string) $mKey);
                                @endphp
                                <li class="px-6 py-2.5 sm:px-8" wire:key="managed-env-{{ md5($mKey) }}">
                                    @if ($mEditing && $envAdvanced)
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
                                            <p class="text-[11px] text-brand-moss">{{ __('Saving creates a .env override for :key — it takes precedence over the :type binding until you delete the override.', ['key' => $mKey, 'type' => $gTypeLabel]) }}</p>
                                            <div class="flex items-center justify-end gap-2">
                                                <x-secondary-button type="button" wire:click="cancelEditEnvVar">{{ __('Cancel') }}</x-secondary-button>
                                                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditedEnvVar">
                                                    <span wire:loading.remove wire:target="saveEditedEnvVar">{{ __('Save override') }}</span>
                                                    <span wire:loading wire:target="saveEditedEnvVar">{{ __('Saving…') }}</span>
                                                </x-primary-button>
                                            </div>
                                        </form>
                                    @else
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div class="flex min-w-0 items-center gap-3 pl-9">
                                                <div class="min-w-0">
                                                    <p class="font-mono text-sm font-semibold text-brand-ink">{{ $mKey }}</p>
                                                    <p class="mt-0.5 break-all font-mono text-[11px] text-brand-moss">
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
                                            @if ($envAdvanced)
                                                <button type="button" wire:click="overrideManagedEnvVar(@js($mKey))" class="shrink-0 rounded-lg border border-brand-ink/10 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink hover:bg-brand-sand/40" title="{{ __('Set a .env value that overrides the binding.') }}">{{ __('Override') }}</button>
                                            @endif
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        @endif

        @if ($variableCount === 0 && $bindingManagedEnv === [])
            <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss">
                    <x-heroicon-o-key class="h-6 w-6" />
                </span>
                <p class="text-sm font-medium text-brand-ink">{{ __('No variables yet.') }}</p>
                <p class="text-xs text-brand-moss">{{ __('Add a variable above, connect a resource, or click Sync from server to import from an existing .env.') }}</p>
            </div>
        @elseif ($variableCount > 0)
            <ul class="divide-y divide-brand-ink/8">
                @if ($filteredEnvMap === [] && ($envSearchTerm !== '' || $selectedEnvGroup !== ''))
                    <li class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">{{ __('No variables match the current filter.') }}</li>
                @endif
                @foreach ($listEnvMap as $key => $value)
                    @php
                        $isRevealed = in_array($key, $revealed_env_keys, true);
                        $isEditing = $editing_env_key === $key;
                        $isInherited = in_array($key, $inheritedKeys, true);
                        $showDiscoveredBadge = $cacheOrigin === 'server' && ! $isInherited;
                        $valueLength = strlen($value);
                        $rowComment = $envComments[$key] ?? null;
                        $overridesBinding = $bindingProvidedKeys[$key] ?? null;
                    @endphp
                    <li class="px-6 py-3 sm:px-8" wire:key="env-row-{{ md5($key) }}">
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
                                            <span>{{ __('Value') }}@if ($editHint['type'] === 'bool')<span class="ml-1 font-normal text-[11px] text-brand-mist">{{ __('(true / false)') }}</span>@elseif ($editHint['type'] === 'enum')<span class="ml-1 font-normal text-[11px] text-brand-mist">{{ __('(pick one)') }}</span>@endif</span>
                                            @if ($editHint['type'] === 'text')
                                                <button type="button" class="text-xs font-medium text-brand-sage hover:underline" @click="showValue = !showValue">
                                                    <span x-show="!showValue">{{ __('Show') }}</span>
                                                    <span x-show="showValue" x-cloak>{{ __('Hide') }}</span>
                                                </button>
                                            @endif
                                        </label>
                                        @if ($editHint['type'] !== 'text')
                                            {{-- Toggle/dropdown for known boolean & enum keys (APP_DEBUG,
                                                 APP_ENV, LOG_LEVEL, MAIL_MAILER, …). The current value is
                                                 always one of the options so nothing is lost. --}}
                                            <select
                                                id="editing_env_value_{{ md5($key) }}"
                                                wire:model="editing_env_value"
                                                class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                                            >
                                                @foreach ($editHint['options'] as $opt)
                                                    <option value="{{ $opt }}">{{ $opt }}</option>
                                                @endforeach
                                            </select>
                                        @else
                                            <input
                                                id="editing_env_value_{{ md5($key) }}"
                                                wire:model="editing_env_value"
                                                x-bind:type="showValue ? 'text' : 'password'"
                                                autocomplete="off"
                                                spellcheck="false"
                                                class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                                            />
                                        @endif
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
                                        <span wire:loading wire:target="saveEditedEnvVar">{{ __('Saving…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sand/40 text-brand-forest ring-brand-ink/10">
                                        <x-heroicon-o-key class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2 font-mono text-sm font-semibold text-brand-ink">
                                            <span>{{ $key }}</span>
                                            @if ($showDiscoveredBadge)
                                                <span
                                                    class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-sky-800 ring-1 ring-inset ring-sky-200/70"
                                                    title="{{ __('Imported from the live .env on the server.') }}"
                                                >
                                                    <x-heroicon-m-magnifying-glass class="h-3 w-3" />
                                                    {{ __('Discovered') }}
                                                </span>
                                            @endif
                                            @if ($isInherited)
                                                <span
                                                    class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-900 ring-1 ring-inset ring-amber-200/70"
                                                    title="{{ __('This site key overrides a workspace-inherited variable.') }}"
                                                >
                                                    <x-heroicon-m-link class="h-3 w-3" />
                                                    {{ __('Override') }}
                                                </span>
                                            @endif
                                            @if ($overridesBinding)
                                                <span
                                                    class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-sky-800 ring-1 ring-inset ring-sky-200/70"
                                                    title="{{ __('This .env value overrides the :type binding\'s connection variable.', ['type' => $bindingTypeLabelsInline[$overridesBinding['type']] ?? $overridesBinding['type']]) }}"
                                                >
                                                    <x-heroicon-m-link class="h-3 w-3" />
                                                    {{ __('Overrides :type', ['type' => $bindingTypeLabelsInline[$overridesBinding['type']] ?? $overridesBinding['type']]) }}
                                                </span>
                                            @endif
                                        </p>
                                        <p class="mt-0.5 break-all font-mono text-[11px] text-brand-moss">
                                            @if ($isRevealed)
                                                {{ $value === '' ? '(empty)' : $value }}
                                            @else
                                                @if ($valueLength === 0)
                                                    <span class="text-brand-mist">(empty)</span>
                                                @else
                                                    {{ str_repeat('•', min(24, max(4, $valueLength))) }}
                                                @endif
                                            @endif
                                        </p>
                                        @if ($rowComment !== null && $rowComment !== '')
                                            {{-- Comment shows in plain (not mono) so it visually
                                                 separates from the KEY/value mono pair. The pre-line
                                                 white-space preserves multi-line comments without
                                                 breaking the grid layout. --}}
                                            <p class="mt-1 whitespace-pre-line text-[11px] italic text-brand-mist">
                                                # {{ $rowComment }}
                                            </p>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        type="button"
                                        wire:click="toggleRevealEnvVar('{{ $key }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        title="{{ $isRevealed ? __('Hide value') : __('Reveal value') }}"
                                    >
                                        @if ($isRevealed)
                                            <x-heroicon-o-eye-slash class="h-3.5 w-3.5" />
                                            {{ __('Hide') }}
                                        @else
                                            <x-heroicon-o-eye class="h-3.5 w-3.5" />
                                            {{ __('Show') }}
                                        @endif
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="editEnvVar('{{ $key }}')"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                        title="{{ __('Edit value') }}"
                                    >
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                        {{ __('Edit') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="confirmRemoveEnvVar('{{ $key }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="confirmRemoveEnvVar('{{ $key }}')"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40"
                                        title="{{ __('Remove variable') }}"
                                        aria-label="{{ __('Remove') }}"
                                    >
                                        <x-heroicon-o-trash class="h-4 w-4" wire:loading.remove wire:target="confirmRemoveEnvVar('{{ $key }}')" />
                                        <span wire:loading wire:target="confirmRemoveEnvVar('{{ $key }}')"><x-spinner variant="forest" size="sm" /></span>
                                    </button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($envAdvanced && $envTotalPages > 1)
                @php
                    $envFrom = ($envCurrentPage - 1) * $envPerPage + 1;
                    $envTo = min($envCurrentPage * $envPerPage, $envFilteredCount);
                @endphp
                <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 px-6 py-3 sm:px-8">
                    <span class="text-[11px] text-brand-mist">{{ __(':from–:to of :total', ['from' => $envFrom, 'to' => $envTo, 'total' => $envFilteredCount]) }}</span>
                    <div class="flex items-center gap-1.5">
                        <button type="button" wire:click="$set('env_page', {{ max(1, $envCurrentPage - 1) }})" @disabled($envCurrentPage <= 1) class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40">
                            <x-heroicon-o-chevron-left class="h-3 w-3" />
                            {{ __('Prev') }}
                        </button>
                        <span class="px-1 text-[11px] font-semibold text-brand-moss">{{ __('Page :p / :n', ['p' => $envCurrentPage, 'n' => $envTotalPages]) }}</span>
                        <button type="button" wire:click="$set('env_page', {{ min($envTotalPages, $envCurrentPage + 1) }})" @disabled($envCurrentPage >= $envTotalPages) class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40">
                            {{ __('Next') }}
                            <x-heroicon-o-chevron-right class="h-3 w-3" />
                        </button>
                    </div>
                </div>
            @endif
        @endif
    </div>

    {{-- "View all" modal: pre-rendered .env block in a read-only textarea
         for select-all + copy. Defaults to masked (KEY=••••) so a casual
         open doesn't leak values into the screen / scrollback; one click
         flips to cleartext. The unmasked text is the same blob the pusher
         would write to the server, so the operator can confirm format. --}}
    @if ($variableCount > 0)
        @php
            // Build a masked version (KEY=••••) and the cleartext version
            // server-side so neither has to be re-derived in JS. Both go
            // into Alpine state below; the textarea binds to whichever
            // mode is currently selected.
            $maskedLines = [];
            $cleartextLines = [];
            $sortedEnvMap = $envMap;
            ksort($sortedEnvMap);
            foreach ($sortedEnvMap as $k => $v) {
                $cleartextLines[] = $k.'='.(string) $v;
                $len = strlen((string) $v);
                $maskedLines[] = $k.'='.($len === 0 ? '' : str_repeat('•', min(24, max(4, $len))));
            }
            $cleartextBlob = implode("\n", $cleartextLines);
            $maskedBlob = implode("\n", $maskedLines);
        @endphp
        <x-modal name="view-all-env-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
            <div
                x-data="{
                    revealed: false,
                    copied: false,
                    masked: @js($maskedBlob),
                    cleartext: @js($cleartextBlob),
                    get text() { return this.revealed ? this.cleartext : this.masked; },
                    async copy() {
                        try { await navigator.clipboard.writeText(this.cleartext); this.copied = true; setTimeout(() => this.copied = false, 1800); } catch (e) {}
                    },
                }"
            >
                <div class="relative border-b border-brand-ink/10 px-6 py-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Site variables') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('All variables') }}</h2>
                    <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                        {{ __('Read-only view of the full .env contents. Values are masked until you click Show — the Copy button always copies the cleartext.') }}
                    </p>
                    <button
                        type="button"
                        x-on:click="$dispatch('close')"
                        class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                        aria-label="{{ __('Close') }}"
                        title="{{ __('Close') }}"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="px-6 py-5">
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-3">
                        <span class="text-[11px] uppercase tracking-[0.16em] text-brand-mist">
                            {{ trans_choice('{1} :count variable|[2,*] :count variables', $variableCount, ['count' => $variableCount]) }}
                        </span>
                        <div class="flex items-center gap-3 text-xs">
                            <button type="button" @click="revealed = !revealed" class="font-medium text-brand-sage hover:underline">
                                <span x-show="!revealed">{{ __('Show values') }}</span>
                                <span x-show="revealed" x-cloak>{{ __('Hide values') }}</span>
                            </button>
                            <button type="button" @click="copy()" class="font-medium text-brand-sage hover:underline">
                                <span x-show="!copied">{{ __('Copy all') }}</span>
                                <span x-show="copied" x-cloak class="text-emerald-700">{{ __('Copied') }}</span>
                            </button>
                        </div>
                    </div>
                    <textarea
                        readonly
                        rows="20"
                        class="w-full rounded-lg border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        x-text="text"
                        @click="$event.target.select()"
                    ></textarea>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                    <p class="mr-auto text-xs text-brand-moss">{{ __('Use Paste .env to apply edits in bulk.') }}</p>
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
                </div>
            </div>
        </x-modal>
    @endif

    {{-- "Edit all" modal: the whole .env in one editable textarea. Saving is a
         full replace (saveAllEnv) — distinct from the additive Bulk import.
         Trait-only (edit_all_env / saveAllEnv), so gated like the trigger. --}}
    @if ($envAdvanced)
    <x-modal name="edit-all-env-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Site variables') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Edit all variables') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">
                {{ __('Edit the entire .env at once. Saving REPLACES every variable — keys you delete here are removed. Changes auto-push to the server.') }}
            </p>
            <button
                type="button"
                x-on:click="$dispatch('close')"
                class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                aria-label="{{ __('Close') }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>
        <div class="px-6 py-5">
            <form wire:submit="saveAllEnv" id="edit-all-env-form">
                <textarea
                    id="edit-all-env-ta"
                    wire:model="edit_all_env"
                    rows="20"
                    spellcheck="false"
                    class="w-full rounded-lg border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                    placeholder="APP_NAME=&quot;My App&quot;&#10;APP_ENV=production&#10;DB_PASSWORD=…"
                ></textarea>
                <x-input-error :messages="$errors->get('edit_all_env')" class="mt-1" />
            </form>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4" x-data="{ copied: false }">
            <p class="mr-auto text-xs text-rose-700">{{ __('Saving replaces ALL variables.') }}</p>
            <button type="button" class="text-xs font-semibold text-brand-sage hover:underline" @click="navigator.clipboard.writeText(document.getElementById('edit-all-env-ta')?.value || ''); copied = true; setTimeout(() => copied = false, 1500)">
                <span x-show="!copied">{{ __('Copy all') }}</span>
                <span x-show="copied" x-cloak class="text-emerald-700">{{ __('Copied') }}</span>
            </button>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="edit-all-env-form" wire:loading.attr="disabled" wire:target="saveAllEnv">
                <span wire:loading.remove wire:target="saveAllEnv">{{ __('Save all') }}</span>
                <span wire:loading wire:target="saveAllEnv">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    {{-- Single-variable "Fix" modal. Opened from a Configuration check warning
         (or any keyed finding) — pre-fills the current value with the same
         hint-aware control as the inline editor (toggle for booleans, dropdown
         for enums, masked text otherwise) and offers a one-click suggested fix
         where we know the safe-in-production value. Saving writes just this key
         and auto-pushes. --}}
    <x-modal name="fix-env-var-modal" maxWidth="lg" overlayClass="bg-brand-ink/40">
        @php
            $fixKey = (string) ($fixing_env_key ?? '');
            $fixVal = (string) ($fixing_env_value ?? '');
            $fixHint = \App\Support\Sites\SiteEnvFieldHints::hint($fixKey, $fixVal);
            $fixWarnings = $fixKey !== '' ? ($envWarningsByKey[$fixKey] ?? []) : [];
            $fixSuggestion = $fixKey !== '' ? $this->envFixSuggestionLabel($fixKey, $fixVal) : null;
        @endphp
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Fix variable') }}</p>
            <h2 class="mt-2 font-mono text-xl font-semibold text-brand-ink">{{ $fixKey !== '' ? $fixKey : __('Variable') }}</h2>
            @foreach ($fixWarnings as $fw)
                <p class="mt-2 flex items-start gap-2 pr-10 text-sm leading-6 {{ $fw['level'] === 'danger' ? 'text-rose-800' : 'text-amber-900' }}">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
                    <span>{{ $fw['message'] }}</span>
                </p>
            @endforeach
            <button
                type="button"
                x-on:click="$dispatch('close')"
                wire:click="cancelFixEnvVar"
                class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                aria-label="{{ __('Close') }}"
            >
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>
        <div class="px-6 py-5">
            <form wire:submit="saveFixedEnvVar" id="fix-env-var-form" class="space-y-3" wire:key="fix-field-{{ md5($fixKey) }}">
                <div x-data="{ showValue: true }">
                    <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="fixing_env_value">
                        <span>{{ __('Value') }}@if ($fixHint['type'] === 'bool')<span class="ml-1 font-normal text-[11px] text-brand-mist">{{ __('(true / false)') }}</span>@elseif ($fixHint['type'] === 'enum')<span class="ml-1 font-normal text-[11px] text-brand-mist">{{ __('(pick one)') }}</span>@endif</span>
                        @if ($fixHint['type'] === 'text')
                            <button type="button" class="text-xs font-medium text-brand-sage hover:underline" @click="showValue = !showValue">
                                <span x-show="!showValue">{{ __('Show') }}</span>
                                <span x-show="showValue" x-cloak>{{ __('Hide') }}</span>
                            </button>
                        @endif
                    </label>
                    @if ($fixHint['type'] !== 'text')
                        <select
                            id="fixing_env_value"
                            wire:model="fixing_env_value"
                            class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                        >
                            @foreach ($fixHint['options'] as $opt)
                                <option value="{{ $opt }}">{{ $opt }}</option>
                            @endforeach
                        </select>
                    @else
                        <input
                            id="fixing_env_value"
                            wire:model="fixing_env_value"
                            x-bind:type="showValue ? 'text' : 'password'"
                            autocomplete="off"
                            spellcheck="false"
                            class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                        />
                    @endif
                    <x-input-error :messages="$errors->get('fixing_env_value')" class="mt-1" />
                </div>
                @if ($fixSuggestion !== null)
                    <button type="button" wire:click="applySuggestedEnvFix" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-sage hover:underline">
                        <x-heroicon-o-sparkles class="h-3.5 w-3.5" />
                        {{ __('Use suggested: :value', ['value' => $fixSuggestion]) }}
                    </button>
                @endif
            </form>
        </div>
        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">{{ __('Saves this one variable and pushes to the server.') }}</p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')" wire:click="cancelFixEnvVar">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="fix-env-var-form" wire:loading.attr="disabled" wire:target="saveFixedEnvVar">
                <span wire:loading.remove wire:target="saveFixedEnvVar">{{ __('Save & push') }}</span>
                <span wire:loading wire:target="saveFixedEnvVar">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>
    @endif

    {{-- Ignored variables — the operator marked these as intentionally unset,
         so they don't count toward "missing required". One-click un-ignore. --}}
    @if ($canIgnoreEnv && $ignoredEnvKeys !== [])
        <div class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-start justify-between gap-3 bg-brand-sand/20 px-5 py-4">
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Ignored variables') }}</p>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('These required variables are ignored for this site — they won\'t block deploys.') }}</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ($ignoredEnvKeys as $ik)
                            <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 font-mono text-[11px] font-semibold text-brand-moss ring-1 ring-inset ring-brand-ink/10">
                                {{ $ik }}
                                <button type="button" wire:click="unignoreEnvKey('{{ $ik }}')" class="text-brand-mist hover:text-rose-700" title="{{ __('Un-ignore') }}" aria-label="{{ __('Un-ignore :key', ['key' => $ik]) }}">
                                    <x-heroicon-o-x-mark class="h-3 w-3" />
                                </button>
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Advanced: relocate the .env file. Hidden behind a disclosure since
         most operators want the default (the docroot's .env, protected by
         the webserver deny rule we inject by default). Power users can move
         it outside the docroot — e.g. /etc/dply/<slug>.env — for an extra
         layer of defense in case the deny rule ever fails or is removed. --}}
    @if ($supportsEnvPush)
        <details class="{{ $card }}">
            <summary class="flex cursor-pointer list-none items-center justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-cog-6-tooth class="h-4 w-4 text-brand-moss" />
                    <span class="text-sm font-semibold text-brand-ink">{{ __('Advanced — .env file location') }}</span>
                </div>
                <span class="font-mono text-[11px] text-brand-mist">{{ $site->effectiveEnvFilePath() }}</span>
            </summary>
            <div class="px-6 py-5 sm:px-8 space-y-3">
                <p class="text-sm text-brand-moss">
                    {{ __('By default the .env file lives at :default.', ['default' => rtrim($site->effectiveEnvDirectory(), '/').'/.env']) }}
                    {{ __('Override the path to relocate it outside the docroot — useful as defense in depth even with the webserver-level deny rule.') }}
                </p>
                <form wire:submit="saveEnvFilePath" class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[18rem]">
                        <x-input-label for="env_file_path_override" :value="__('Absolute path on host (leave blank for default)')" />
                        <x-text-input
                            id="env_file_path_override"
                            wire:model="env_file_path_override"
                            class="mt-1 block w-full font-mono text-sm"
                            placeholder="/etc/dply/{{ $site->slug }}.env"
                            autocomplete="off"
                            spellcheck="false"
                        />
                        <x-input-error :messages="$errors->get('env_file_path_override')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEnvFilePath">
                        <span wire:loading.remove wire:target="saveEnvFilePath">{{ __('Save path') }}</span>
                        <span wire:loading wire:target="saveEnvFilePath">{{ __('Saving…') }}</span>
                    </x-primary-button>
                </form>
                <p class="text-[11px] text-brand-moss">
                    {{ __('Push will mkdir -p the parent directory and write the file there. Sync and Load fetch from this path. The webserver deny rule for /.env still applies for the default location.') }}
                </p>
            </div>
        </details>
    @endif

    {{-- Bindings — managed-resource attachments that auto-inject connection
         env vars (DATABASE_URL, REDIS_URL, etc.). v1 surfaces what the
         deployment contract sees today (mostly VM-derived) as a read-only
         list so operators can confirm what the deploy job will read from
         the resource graph. Attach / provision / detach UI lands in a
         follow-up alongside per-site binding records.

         Only rendered when the host component provides the binding actions +
         modal state (Show/Settings). The Deployments-hub Environment tab
         reuses this same partial but doesn't carry the binding plumbing, so
         the whole block is gated to avoid undefined $bindingModal* vars. --}}
    @if (method_exists($this, 'openBindingModal'))
    @php
        $siteBindings = app(\App\Services\Deploy\SiteResourceBindingResolver::class)->forSite($site);
        $bindingStatusBadge = [
            'configured' => 'bg-emerald-100 text-emerald-800',
            'pending' => 'bg-amber-100 text-amber-900',
        ];
        $bindingTypeLabels = [
            'database' => __('Database'),
            'redis' => __('Redis'),
            'queue' => __('Queue'),
            'storage' => __('Object storage'),
            'scheduler' => __('Scheduler'),
            'workers' => __('Workers'),
            'publication' => __('Publication'),
        ];
        // Connection resources (database, redis, queue, storage) now surface
        // inline with the variables above as managed rows, so this card carries
        // only the runtime resources that don't map to env vars.
        $resourceBindings = array_values(array_filter(
            $siteBindings,
            fn ($b) => ! in_array($b->type, ['database', 'redis', 'queue', 'storage'], true),
        ));
    @endphp
    @if ($resourceBindings !== [])
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-8 sm:py-6">
            <div class="flex items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 bg-sky-50 text-sky-700 ring-sky-200">
                    <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Resources') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Runtime resources') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('Runtime resources that don\'t map to environment variables — the scheduler, queue workers, and publication. Databases, Redis, queue, and storage appear inline with the variables above as managed rows.') }}
                    </p>
                </div>
            </div>
        </div>

        <ul class="divide-y divide-brand-ink/10">
            @foreach ($resourceBindings as $binding)
                <li class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-8">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-brand-ink">
                            {{ $bindingTypeLabels[$binding->type] ?? str($binding->type)->replace('_', ' ')->title() }}
                            @if ($binding->required)
                                <span class="ml-2 inline-flex items-center rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Required') }}</span>
                            @endif
                        </p>
                        <p class="mt-1 text-xs text-brand-moss">
                            @if ($binding->name)
                                <span class="font-mono">{{ $binding->name }}</span> · {{ __('source:') }} <span class="font-mono">{{ $binding->source }}</span>
                            @else
                                {{ __('Not attached — derived from') }} <span class="font-mono">{{ $binding->source }}</span>
                            @endif
                        </p>
                        @if (! empty($binding->config['source_server_id']))
                            <p class="mt-1 inline-flex items-center gap-1 text-xs text-brand-moss">
                                <x-heroicon-o-globe-alt class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Connects over the private network to a database on another server.') }}
                            </p>
                        @endif
                        @if (! empty($binding->config['needs_remote_access']))
                            <p class="mt-1 inline-flex items-center gap-1 text-xs text-amber-700">
                                <x-heroicon-o-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Remote access is off on the source database — enable it (and allow this server\'s private IP) in the server Databases workspace or the deploy will fail to connect.') }}
                            </p>
                        @endif
                        @if (! empty($binding->config['last_error']))
                            <p class="mt-1 text-xs text-rose-700">{{ $binding->config['last_error'] }}</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-semibold uppercase tracking-[0.14em] {{ $bindingStatusBadge[$binding->status] ?? 'bg-brand-sand/40 text-brand-moss' }}">
                            {{ $binding->status }}
                        </span>
                        @if ($binding->bindingId)
                            <button type="button" wire:click="detachBinding('{{ $binding->bindingId }}')" wire:confirm="{{ __('Detach this :type binding? Its connection variables stop being injected at deploy.', ['type' => $binding->type]) }}" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                                {{ __('Detach') }}
                            </button>
                        @elseif ($binding->manageable)
                            @if ($binding->type === 'database')
                                <button type="button" wire:click="openBindingModal('database', 'attach')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-link class="h-3.5 w-3.5" />
                                    {{ __('Attach existing') }}
                                </button>
                                <button type="button" wire:click="openBindingModal('database', 'provision')" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-2.5 py-1 text-[11px] font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                    {{ __('Provision new') }}
                                </button>
                            @else
                                <button type="button" wire:click="openBindingModal('{{ $binding->type }}', 'attach')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-link class="h-3.5 w-3.5" />
                                    {{ __('Configure') }}
                                </button>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-8 text-xs text-brand-moss">
            {{ __('These resources back the runtime rather than the .env. Publication is managed by the runtime and can\'t be detached here. Database, Redis, queue, and storage are managed inline with the variables above — use Connect resource to add one.') }}
        </div>
    </section>
    @endif

    {{-- Shared attach / provision modal. Body switches on the chosen type +
         mode; form values live in the loose $bindingForm array on the
         component (see ManagesSiteBindings). Rendered whenever the host
         component carries the binding actions — both the Resources card and the
         "Connect resource" dropdown above open it. --}}
    <x-modal name="site-binding-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        @php $bindingModalLabel = $bindingTypeLabels[$bindingModalType] ?? str($bindingModalType)->replace('_', ' ')->title(); @endphp
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $bindingModalMode === 'provision' ? __('Provision new') : __('Attach existing') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ $bindingModalLabel ?: __('Binding') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40" aria-label="{{ __('Close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="space-y-4 px-6 py-6">
            @if ($bindingModalType === 'database' && $bindingModalMode === 'attach')
                <div>
                    <x-input-label for="binding_db_target" :value="__('Database')" />
                    <select id="binding_db_target" wire:model="bindingForm.target_id" class="dply-input">
                        <option value="">{{ __('Choose a database…') }}</option>
                        @foreach ($bindingTargets as $target)
                            <option value="{{ $target['id'] }}">{{ $target['label'] }}</option>
                        @endforeach
                    </select>
                    @if ($bindingTargets === [])
                        <p class="mt-2 text-xs text-brand-moss">{{ __('No reachable databases yet. Use Provision new, create one in the server Databases workspace, or add a server to this private network.') }}</p>
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Lists databases on this server and on peers in the same private network. Injects DATABASE_URL and DB_* at deploy — peers connect over the private IP, so the database must allow remote access from this server.') }}</p>
                    @endif
                </div>
            @elseif ($bindingModalType === 'database' && $bindingModalMode === 'provision')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="binding_db_engine" :value="__('Engine')" />
                        <select id="binding_db_engine" wire:model="bindingForm.engine" class="dply-input">
                            <option value="mysql">{{ __('MySQL / MariaDB') }}</option>
                            <option value="postgres">{{ __('PostgreSQL') }}</option>
                            <option value="sqlite">{{ __('SQLite') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="binding_db_name" :value="__('Database name')" />
                        <x-text-input id="binding_db_name" wire:model="bindingForm.name" class="mt-1 block w-full font-mono text-sm" placeholder="app_production" />
                    </div>
                </div>
                <p class="text-xs text-brand-moss">{{ __('Creates the database on this site\'s server with generated credentials and injects the connection variables.') }}</p>
            @elseif ($bindingModalType === 'queue')
                <div>
                    <x-input-label for="binding_queue_driver" :value="__('Queue driver')" />
                    <select id="binding_queue_driver" wire:model="bindingForm.driver" class="dply-input">
                        <option value="database">{{ __('Database') }}</option>
                        <option value="redis">{{ __('Redis') }}</option>
                    </select>
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Sets QUEUE_CONNECTION. Redis requires the Redis binding to be attached too.') }}</p>
                </div>
            @elseif ($bindingModalType === 'storage')
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-input-label for="binding_storage_bucket" :value="__('Bucket')" />
                        <x-text-input id="binding_storage_bucket" wire:model="bindingForm.bucket" class="mt-1 block w-full font-mono text-sm" placeholder="my-app-assets" />
                    </div>
                    <div>
                        <x-input-label for="binding_storage_key" :value="__('Access key ID')" />
                        <x-text-input id="binding_storage_key" wire:model="bindingForm.access_key_id" class="mt-1 block w-full font-mono text-sm" />
                    </div>
                    <div>
                        <x-input-label for="binding_storage_secret" :value="__('Secret access key')" />
                        <x-text-input id="binding_storage_secret" type="password" wire:model="bindingForm.secret_access_key" class="mt-1 block w-full font-mono text-sm" />
                    </div>
                    <div>
                        <x-input-label for="binding_storage_region" :value="__('Region (optional)')" />
                        <x-text-input id="binding_storage_region" wire:model="bindingForm.region" class="mt-1 block w-full font-mono text-sm" placeholder="us-east-1" />
                    </div>
                    <div>
                        <x-input-label for="binding_storage_endpoint" :value="__('Endpoint (optional)')" />
                        <x-text-input id="binding_storage_endpoint" wire:model="bindingForm.endpoint" class="mt-1 block w-full font-mono text-sm" placeholder="https://nyc3.digitaloceanspaces.com" />
                    </div>
                </div>
                <p class="text-xs text-brand-moss">{{ __('Injects FILESYSTEM_DISK=s3 and the AWS_* connection variables at deploy.') }}</p>
            @elseif ($bindingModalType === 'redis')
                <div>
                    <x-input-label for="binding_redis_target" :value="__('Redis service')" />
                    <select id="binding_redis_target" wire:model="bindingForm.target_id" class="dply-input">
                        <option value="">{{ __('Choose a Redis service…') }}</option>
                        @foreach ($bindingTargets as $target)
                            <option value="{{ $target['id'] }}">{{ $target['label'] }}</option>
                        @endforeach
                    </select>
                    @if ($bindingTargets === [])
                        <p class="mt-2 text-xs text-brand-moss">{{ __('No Redis-compatible service is reachable. Install Redis or Valkey from the server Caches workspace, or add a server with one to this private network.') }}</p>
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Lists Redis-family services on this server and on peers in the same private network. Injects REDIS_HOST / REDIS_PORT / REDIS_CLIENT (plus password and prefix when set) at deploy — peers connect over the private IP.') }}</p>
                    @endif
                </div>
            @else
                <p class="text-sm text-brand-moss">{{ __('Records this binding so deploy preflight treats it as configured.') }}</p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="button" wire:click="saveBinding" wire:loading.attr="disabled" wire:target="saveBinding">
                <span wire:loading.remove wire:target="saveBinding">{{ $bindingModalMode === 'provision' ? __('Provision') : __('Attach') }}</span>
                <span wire:loading wire:target="saveBinding">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>
    @endif

    <x-cli-snippet
        :intro="__('Manage env via CLI when you have many keys at once:')"
        :commands="[
            ['label' => __('Set one'), 'command' => 'dply:site:env-set '.$site->slug.' KEY=value'],
            ['label' => __('Bulk import from .env'), 'command' => 'dply:site:env-import '.$site->slug.' --file=.env'],
            ['label' => __('Export current as .env'), 'command' => 'dply:site:env-export '.$site->slug.' --to=.env'],
            ['label' => __('Diff cache vs server'), 'command' => 'dply:site:env-diff '.$site->slug],
        ]"
    />
</section>
