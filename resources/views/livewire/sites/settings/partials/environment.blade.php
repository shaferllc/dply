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

    // Gate opt-out state — feature-detected so the partial works in all host
    // components (Show, Settings, DeploymentsList, SiteSetup).
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
    $allEnvWarnings = app(\App\Services\Sites\SiteEnvValidator::class)->validate($envMapForValidation);
    $canIgnoreEnvWarnings = method_exists($this, 'ignoreEnvWarning');
    $suppressedEnvWarningKeys = $canIgnoreEnvWarnings ? $this->suppressedEnvWarningKeys() : [];
    $envWarnings = $suppressedEnvWarningKeys !== []
        ? array_values(array_filter($allEnvWarnings, fn ($w) => empty($w['key']) || ! in_array($w['key'], $suppressedEnvWarningKeys, true)))
        : $allEnvWarnings;
    $suppressedEnvWarningCount = count($allEnvWarnings) - count($envWarnings);
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
        'cache' => __('Cache'),
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

    @include('livewire.sites.settings.partials.environment.seed-prompt')

    @include('livewire.sites.settings.partials.environment.import-modal')

    @include('livewire.sites.settings.partials.environment.configuration-check')

    @include('livewire.sites.settings.partials.environment.suggested-fixes')

    @include('livewire.sites.settings.partials.environment.console-banner')

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

    @include('livewire.sites.settings.partials.environment.docroot-warning')

    @include('livewire.sites.settings.partials.environment.parse-errors')

    @include('livewire.sites.settings.partials.environment.missing-vars')

    @include('livewire.sites.settings.partials.environment.add-env-modal')

    @include('livewire.sites.settings.partials.environment.paste-env-modal')

    @include('livewire.sites.settings.partials.environment.add-missing-env-modal')

    @include('livewire.sites.settings.partials.environment.inherited')

    @include('livewire.sites.settings.partials.environment.variables-list')

    @include('livewire.sites.settings.partials.environment.view-all-modal')

    @include('livewire.sites.settings.partials.environment.advanced-modals')

    @include('livewire.sites.settings.partials.environment.fix-binding-modal')

    @include('livewire.sites.settings.partials.environment.ignored-vars')

    @include('livewire.sites.settings.partials.environment.advanced-path')

    @include('livewire.sites.settings.partials.environment.resources')

    <x-cli-snippet
        :intro="__('Manage env via CLI when you have many keys at once:')"
        :commands="[
            ['label' => __('Set one'), 'command' => 'dply sites:env:set '.$site->slug.' KEY=value'],
            ['label' => __('Bulk import from .env'), 'command' => 'dply sites:env:import '.$site->slug.' --file=.env'],
            ['label' => __('Export current as .env'), 'command' => 'dply sites:env:export '.$site->slug.' --to=.env'],
            ['label' => __('Diff cache vs server'), 'command' => 'dply sites:env:diff '.$site->slug],
        ]"
    />
</section>
