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
        $siteBindings = app(\App\Modules\Deploy\Services\SiteResourceBindingResolver::class)->forSite($site);
        $bindingStatusBadge = [
            'configured' => 'bg-emerald-100 text-emerald-800',
            'pending' => 'bg-amber-100 text-amber-900',
        ];
        $bindingTypeLabels = [
            'database' => __('Database'),
            'redis' => __('Redis'),
            'queue' => __('Queue'),
            'cache' => __('Cache'),
            'session' => __('Sessions'),
            'storage' => __('Object storage'),
            'logging' => __('Logging'),
            'mail' => __('Mail'),
            'error_tracking' => __('Error tracking'),
            'ai' => __('AI / LLM'),
            'captcha' => __('CAPTCHA'),
            'sms' => __('SMS / push'),
            'search' => __('Search'),
            'payments' => __('Payments'),
            'oauth' => __('OAuth login'),
            'scheduler' => __('Scheduler'),
            'workers' => __('Workers'),
            'publication' => __('Publication'),
            'broadcasting' => __('Broadcasting'),
        ];
        // Connection resources (database, redis, queue, storage) and logging
        // surface elsewhere (inline with env vars, or on the Logs tab), so this
        // card carries only the runtime resources that don't map to env vars.
        $resourceBindings = array_values(array_filter(
            $siteBindings,
            fn ($b) => ! in_array($b->type, ['database', 'redis', 'queue', 'cache', 'session', 'storage', 'logging', 'mail', 'broadcasting', 'error_tracking', 'ai', 'captcha', 'sms', 'search', 'payments', 'oauth'], true),
        ));
    @endphp
    {{-- $bindingModalOnly: the Resources hub includes this partial solely to
         get the shared site-binding-modal into its DOM; it renders its own
         resource UI, so suppress this legacy read-only card there. --}}
    @if ($resourceBindings !== [] && ! ($bindingModalOnly ?? false))
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
                        {{ __('Runtime resources that don\'t map to environment variables — the scheduler, queue workers, and publication. Databases, Redis, queue, cache, and storage appear inline with the variables above as managed rows.') }}
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
                                <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Connects over the private network to a database on another server.') }}
                            </p>
                        @endif
                        @if (! empty($binding->config['needs_remote_access']))
                            <p class="mt-1 inline-flex items-center gap-1 text-xs text-amber-700">
                                <x-heroicon-o-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
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
                            <button type="button" wire:click="openConfirmActionModal('detachBinding', @js([(string) $binding->bindingId]), @js(__('Detach binding?')), @js(__('Detach this :type binding? Its connection variables stop being injected at deploy.', ['type' => $binding->type])), @js(__('Detach')), true)" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                <x-heroicon-o-x-mark class="h-4 w-4" />
                                {{ __('Detach') }}
                            </button>
                        @elseif ($binding->manageable)
                            @if ($binding->type === 'database')
                                <button type="button" wire:click="openBindingModal('database', 'attach')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-link class="h-4 w-4" />
                                    {{ __('Attach existing') }}
                                </button>
                                <button type="button" wire:click="openBindingModal('database', 'provision')" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-2.5 py-1 text-[11px] font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                                    <x-heroicon-o-plus class="h-4 w-4" />
                                    {{ __('Provision new') }}
                                </button>
                            @else
                                <button type="button" wire:click="openBindingModal('{{ $binding->type }}', 'attach')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                    <x-heroicon-o-link class="h-4 w-4" />
                                    {{ __('Configure') }}
                                </button>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>

        <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-8 text-xs text-brand-moss">
            {{ __('These resources back the runtime rather than the .env. Publication is managed by the runtime and can\'t be detached here. Database, Redis, queue, cache, and storage are managed inline with the variables above — use Connect resource to add one.') }}
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
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $bindingModalMode === 'provision' ? __('Provision new') : (in_array($bindingModalType, ['logging', 'mail', 'broadcasting', 'error_tracking', 'ai', 'captcha', 'sms', 'search', 'payments', 'oauth']) ? __('Configure') : __('Attach existing')) }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ $bindingModalLabel ?: __('Binding') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40" aria-label="{{ __('Close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="space-y-4 px-6 py-6">
            @if (in_array($bindingModalType, ['storage', 'database'], true))
                {{-- One entry, two modes: attach an existing resource or have dply
                     provision a fresh one. Switching re-seeds the form server-side
                     (see setBindingMode). Shown for both storage and database since
                     each supports an attach-existing and a provision-new path. --}}
                <div class="inline-flex rounded-lg border border-brand-ink/15 bg-brand-sand/30 p-0.5 text-xs font-semibold">
                    <button type="button" wire:click="setBindingMode('attach')" class="rounded-md px-3 py-1.5 transition-colors {{ $bindingModalMode !== 'provision' ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">
                        {{ __('Attach existing') }}
                    </button>
                    <button type="button" wire:click="setBindingMode('provision')" class="rounded-md px-3 py-1.5 transition-colors {{ $bindingModalMode === 'provision' ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">
                        {{ __('Provision new') }}
                    </button>
                </div>
            @endif
            @if ($bindingModalType === 'database' && $bindingModalMode === 'attach')
                @php
                    // Derive the selected engine from the targets list so we can gate
                    // engine-specific advanced fields without a separate round-trip.
                    $selectedDbTarget = collect($bindingTargets)->firstWhere('id', $bindingForm['target_id'] ?? '');
                    $selectedDbEngine = $selectedDbTarget['engine'] ?? null;
                    $dbIsMysql = in_array($selectedDbEngine, ['mysql', 'mariadb'], true);
                    $dbIsPgsql = $selectedDbEngine === 'postgres';

                    // Pre-open sections when re-editing a binding that already had them.
                    $dbHasReplica = ($bindingForm['read_replica_type'] ?? '') !== '';
                    $dbHasAdvanced =
                        ($bindingForm['db_prefix'] ?? '') !== '' ||
                        ($bindingForm['db_charset'] ?? '') !== '' ||
                        ($bindingForm['db_collation'] ?? '') !== '' ||
                        ($bindingForm['db_strict'] ?? '') !== '' ||
                        ($bindingForm['db_engine'] ?? '') !== '' ||
                        ($bindingForm['db_socket'] ?? '') !== '' ||
                        ($bindingForm['db_schema'] ?? '') !== '' ||
                        ($bindingForm['db_sslmode'] ?? '') !== '' ||
                        ($bindingForm['db_timezone'] ?? '') !== '';

                    // config/database.php snippet for read/write split wiring.
                    $dbConnKey = $selectedDbEngine === 'postgres' ? 'pgsql' : 'mysql';
                    $dbReadWriteSnippet = "'{$dbConnKey}' => [\n"
                        . "    'read'   => ['host' => [env('DB_READ_HOST', env('DB_HOST'))]],\n"
                        . "    'write'  => ['host' => [env('DB_HOST')]],\n"
                        . "    'sticky' => env('DB_STICKY', true),\n"
                        . "    // … keep your existing keys\n"
                        . "],";
                @endphp
                <div x-data="{ replica: @js($dbHasReplica), advanced: @js($dbHasAdvanced) }" class="space-y-4">

                {{-- Primary database --}}
                <div>
                    <x-input-label for="binding_db_target" :value="__('Database')" />
                    <x-binding-target-select
                        id="binding_db_target"
                        model="bindingForm.target_id"
                        :live="true"
                        :targets="$bindingTargets"
                        :selected="$bindingForm['target_id'] ?? ''"
                        :placeholder="__('Choose a database…')"
                    />
                    @if ($bindingTargets === [])
                        <p class="mt-2 text-xs text-brand-moss">{{ __('No reachable databases yet. Create one on this server, or add a server to this private network.') }}</p>
                        <button type="button" wire:click="openBindingModal('database', 'provision')" class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                            <x-heroicon-o-plus class="h-4 w-4" />
                            {{ __('Provision new database') }}
                        </button>
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Grouped by location: services on this server (loopback) and on private-network peers (private IP — adds a network hop and must allow remote access from this server). Each option shows how many other apps already use it; sharing one database/Redis means a shared keyspace, so set a prefix or a separate database. Injects DATABASE_URL and DB_* at deploy.') }}</p>
                    @endif
                </div>

                @if ($bindingTargets !== [])
                {{-- Read replica --}}
                <div class="border-t border-brand-ink/10 pt-4">
                    <label class="flex cursor-pointer select-none items-center gap-2 text-sm">
                        <input type="checkbox" x-model="replica"
                            x-on:change="if (!replica) { $wire.set('bindingForm.read_replica_type', '') }"
                            class="rounded border-brand-ink/20 text-brand-forest shadow-sm focus:ring-brand-sage/30" />
                        <span class="font-medium text-brand-ink">{{ __('Add read replica') }}</span>
                        <span class="text-xs text-brand-moss">{{ __('(read/write split)') }}</span>
                    </label>

                    <div x-show="replica" class="mt-4 space-y-4">
                        <div>
                            <x-input-label :value="__('Replica source')" />
                            <div class="mt-1 inline-flex rounded-lg border border-brand-ink/15 bg-brand-sand/30 p-0.5 text-xs font-semibold">
                                <button type="button" wire:click="$set('bindingForm.read_replica_type', 'managed')"
                                    class="rounded-md px-3 py-1.5 transition-colors {{ ($bindingForm['read_replica_type'] ?? '') === 'managed' ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">
                                    {{ __('Managed database') }}
                                </button>
                                <button type="button" wire:click="$set('bindingForm.read_replica_type', 'manual')"
                                    class="rounded-md px-3 py-1.5 transition-colors {{ ($bindingForm['read_replica_type'] ?? '') === 'manual' ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">
                                    {{ __('Custom host') }}
                                </button>
                            </div>
                        </div>

                        @if (($bindingForm['read_replica_type'] ?? '') === 'managed')
                            <div>
                                <x-input-label for="binding_db_replica_id" :value="__('Replica database')" />
                                <select id="binding_db_replica_id" wire:model="bindingForm.read_replica_id" class="dply-input">
                                    <option value="">{{ __('Choose a replica…') }}</option>
                                    @foreach ($bindingTargets as $target)
                                        @if ($target['id'] !== ($bindingForm['target_id'] ?? ''))
                                            <option value="{{ $target['id'] }}">{{ $target['label'] }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <p class="mt-1.5 text-xs text-brand-moss">{{ __('dply resolves the host automatically (loopback or private IP, same as the primary). Credentials are shared unless the replica DB uses different ones.') }}</p>
                            </div>
                        @elseif (($bindingForm['read_replica_type'] ?? '') === 'manual')
                            <div>
                                <x-input-label for="binding_db_replica_host" :value="__('Replica host')" />
                                <x-text-input id="binding_db_replica_host" wire:model="bindingForm.read_replica_host" class="mt-1 block w-full font-mono text-sm" placeholder="10.0.0.5" />
                                <p class="mt-1.5 text-xs text-brand-moss">{{ __('The IP or hostname your app server can reach the replica at.') }}</p>
                            </div>
                        @endif

                        @if (($bindingForm['read_replica_type'] ?? '') !== '')
                            <div class="grid gap-4 sm:grid-cols-2">
                                @if (($bindingForm['read_replica_type'] ?? '') === 'manual')
                                    <div>
                                        <x-input-label for="binding_db_replica_port" :value="__('Port (optional)')" />
                                        <x-text-input id="binding_db_replica_port" wire:model="bindingForm.read_replica_port" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('same as primary') }}" />
                                    </div>
                                @endif
                                <div>
                                    <x-input-label for="binding_db_replica_user" :value="__('Username (optional)')" />
                                    <x-text-input id="binding_db_replica_user" wire:model="bindingForm.read_replica_username" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('same as primary') }}" />
                                </div>
                                <div>
                                    <x-input-label for="binding_db_replica_pass" :value="__('Password (optional)')" />
                                    <x-text-input id="binding_db_replica_pass" type="password" wire:model="bindingForm.read_replica_password" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('same as primary') }}" autocomplete="new-password" />
                                </div>
                            </div>
                            <div>
                                <p class="mb-1.5 text-xs text-brand-moss">{{ __('Injects DB_READ_HOST (+ DB_READ_PORT, DB_READ_USERNAME, DB_READ_PASSWORD when they differ) and DB_STICKY=true. Wire up the split in config/database.php:') }}</p>
                                <pre class="overflow-x-auto rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-3 font-mono text-[11px] leading-relaxed text-brand-ink">{{ $dbReadWriteSnippet }}</pre>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Advanced options --}}
                <div class="border-t border-brand-ink/10 pt-4">
                    <button type="button" x-on:click="advanced = !advanced"
                        class="flex w-full items-center justify-between text-left text-sm font-medium text-brand-ink hover:text-brand-forest">
                        <span>{{ __('Advanced options') }}</span>
                        <x-heroicon-o-chevron-down class="h-4 w-4 transition-transform duration-150" x-bind:class="{ 'rotate-180': advanced }" />
                    </button>

                    <div x-show="advanced" class="mt-4 space-y-4">
                        {{-- Common: prefix + timezone (all engines) --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="binding_db_prefix" :value="__('Table prefix (DB_PREFIX)')" />
                                <x-text-input id="binding_db_prefix" wire:model="bindingForm.db_prefix" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('none') }}" />
                            </div>
                            <div>
                                <x-input-label for="binding_db_timezone" :value="__('Timezone (DB_TIMEZONE)')" />
                                <x-text-input id="binding_db_timezone" wire:model="bindingForm.db_timezone" class="mt-1 block w-full font-mono text-sm" placeholder="UTC" />
                            </div>
                        </div>

                        @if ($dbIsMysql)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="binding_db_charset" :value="__('Charset (DB_CHARSET)')" />
                                <x-text-input id="binding_db_charset" wire:model="bindingForm.db_charset" class="mt-1 block w-full font-mono text-sm" placeholder="utf8mb4" />
                            </div>
                            <div>
                                <x-input-label for="binding_db_collation" :value="__('Collation (DB_COLLATION)')" />
                                <x-text-input id="binding_db_collation" wire:model="bindingForm.db_collation" class="mt-1 block w-full font-mono text-sm" placeholder="utf8mb4_unicode_ci" />
                            </div>
                            <div>
                                <x-input-label for="binding_db_strict" :value="__('Strict mode (DB_STRICT)')" />
                                <select id="binding_db_strict" wire:model="bindingForm.db_strict" class="dply-input">
                                    <option value="">{{ __('Use default (true)') }}</option>
                                    <option value="true">true</option>
                                    <option value="false">{{ __('false — legacy apps') }}</option>
                                </select>
                            </div>
                            <div>
                                <x-input-label for="binding_db_engine_type" :value="__('Storage engine (DB_ENGINE)')" />
                                <x-text-input id="binding_db_engine_type" wire:model="bindingForm.db_engine" class="mt-1 block w-full font-mono text-sm" placeholder="InnoDB" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label for="binding_db_socket" :value="__('Unix socket path (DB_SOCKET)')" />
                                <x-text-input id="binding_db_socket" wire:model="bindingForm.db_socket" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('leave blank to use TCP') }}" />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Only set when the app and database are on the same box and you want socket instead of TCP.') }}</p>
                            </div>
                        </div>
                        @elseif ($dbIsPgsql)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="binding_db_charset" :value="__('Charset (DB_CHARSET)')" />
                                <x-text-input id="binding_db_charset" wire:model="bindingForm.db_charset" class="mt-1 block w-full font-mono text-sm" placeholder="utf8" />
                            </div>
                            <div>
                                <x-input-label for="binding_db_schema" :value="__('Schema (DB_SCHEMA)')" />
                                <x-text-input id="binding_db_schema" wire:model="bindingForm.db_schema" class="mt-1 block w-full font-mono text-sm" placeholder="public" />
                            </div>
                            <div class="sm:col-span-2">
                                <x-input-label for="binding_db_sslmode" :value="__('SSL mode (DB_SSLMODE)')" />
                                <select id="binding_db_sslmode" wire:model="bindingForm.db_sslmode" class="dply-input">
                                    <option value="">{{ __('Use default (prefer)') }}</option>
                                    <option value="disable">disable</option>
                                    <option value="allow">allow</option>
                                    <option value="prefer">prefer</option>
                                    <option value="require">require</option>
                                    <option value="verify-ca">verify-ca</option>
                                    <option value="verify-full">verify-full</option>
                                </select>
                            </div>
                        </div>
                        @elseif ($selectedDbEngine === null)
                        <p class="text-xs italic text-brand-moss">{{ __('Select a database above to see engine-specific options (charset, strict mode, SSL mode, etc.).') }}</p>
                        @endif
                    </div>
                </div>
                @endif

                </div>
            @elseif ($bindingModalType === 'database' && $bindingModalMode === 'provision')
                @php
                    $dbPlacements = $this->databasePlacements();
                @endphp
                <div
                    x-data="{
                        engine: $wire.entangle('bindingForm.engine'),
                        placement: $wire.entangle('bindingForm.placement'),
                        placements: @js(collect($dbPlacements)->mapWithKeys(fn ($p) => [$p['key'] => ['engines' => $p['engines'], 'available' => $p['available']]])),
                        validFor(eng) {
                            return Object.keys(this.placements).filter((k) => this.placements[k].engines.includes(eng) && this.placements[k].available);
                        },
                    }"
                    x-effect="
                        const valid = validFor(engine);
                        if (valid.length && !valid.includes(placement)) { placement = valid[0]; }
                    "
                    class="space-y-4"
                >
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="binding_db_engine" :value="__('Engine')" />
                            <select id="binding_db_engine" x-model="engine" class="dply-input">
                                <option value="mysql">{{ __('MySQL / MariaDB') }}</option>
                                <option value="postgres">{{ __('PostgreSQL') }}</option>
                                {{-- Redis here means a managed cluster or a serverless vendor
                                     (Upstash) — on-box Redis is attached via the Redis resource.
                                     The placement cards filter to redis-capable backends. --}}
                                <option value="redis">{{ __('Redis') }}</option>
                                <option value="sqlite">{{ __('SQLite') }}</option>
                            </select>
                        </div>
                        <div>
                            <x-input-label for="binding_db_name" :value="__('Database name')" />
                            <x-text-input id="binding_db_name" wire:model="bindingForm.name" class="mt-1 block w-full font-mono text-sm" placeholder="app_production" />
                        </div>
                    </div>

                    {{-- Placement: where the database lives. Cards filter to the backends
                         that support the chosen engine; an unavailable managed card (no
                         connected provider credential) is shown disabled with a hint. --}}
                    <div>
                        <x-input-label :value="__('Where should it live?')" />
                        <div class="mt-2 space-y-2">
                            @foreach ($dbPlacements as $p)
                                <label
                                    x-show="@js($p['engines']).includes(engine)"
                                    @class([
                                        'flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors',
                                        'border-brand-ink/15 hover:border-brand-ink/30' => $p['available'],
                                        'cursor-not-allowed border-brand-ink/10 opacity-60' => ! $p['available'],
                                    ])
                                    :class="placement === '{{ $p['key'] }}' ? 'border-brand-ink ring-1 ring-brand-ink bg-brand-sand/30' : ''"
                                >
                                    <input type="radio" x-model="placement" value="{{ $p['key'] }}" @disabled(! $p['available']) class="mt-1">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-brand-ink">{{ $p['label'] }}</span>
                                        <span class="block text-xs text-brand-moss">{{ $p['sublabel'] }}</span>
                                        @if ($p['note'])
                                            <span class="mt-0.5 block text-xs font-medium text-amber-700">{{ $p['note'] }}</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    {{-- Managed clusters are sized; on-box databases just share the host. --}}
                    <div x-show="placement === 'managed'" x-cloak>
                        <x-input-label for="binding_db_size" :value="__('Cluster size')" />
                        <select id="binding_db_size" wire:model="bindingForm.size" class="dply-input">
                            <option value="small">{{ __('Small — 1 vCPU / 1 GB · ~$15/mo') }}</option>
                            <option value="medium">{{ __('Medium — 1 vCPU / 2 GB · ~$30/mo') }}</option>
                            <option value="large">{{ __('Large — 2 vCPU / 4 GB · ~$60/mo') }}</option>
                        </select>
                    </div>

                    {{-- Dedicated VM: a real server sized from the provider's catalog. --}}
                    <div x-show="placement === 'dedicated_vm'" x-cloak>
                        <x-input-label for="binding_db_vm_size" :value="__('Server size')" />
                        <select id="binding_db_vm_size" wire:model="bindingForm.vm_size" class="dply-input">
                            @forelse ($dedicatedVmSizes as $s)
                                <option value="{{ $s['value'] }}">{{ $s['label'] }}</option>
                            @empty
                                <option value="">{{ __('No sizes available for this provider/region') }}</option>
                            @endforelse
                        </select>
                    </div>

                    {{-- BYO serverless vendors: pick a vendor region + connect an API key. --}}
                    @foreach (collect($dbPlacements)->where('serverless', true) as $sv)
                        <div x-show="placement === '{{ $sv['key'] }}'" x-cloak class="space-y-3 rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-3">
                            <div>
                                <x-input-label for="binding_db_vendor_region_{{ $sv['key'] }}" :value="__(':vendor region', ['vendor' => $sv['label']])" />
                                <select id="binding_db_vendor_region_{{ $sv['key'] }}" wire:model="bindingForm.vendor_region" class="dply-input">
                                    @foreach ($sv['regions'] as $r)
                                        <option value="{{ $r['value'] }}">{{ $r['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @if (! empty($sv['account_label']))
                                <div>
                                    <x-input-label for="binding_db_vendor_account_{{ $sv['key'] }}" :value="$sv['account_label']" />
                                    <x-text-input id="binding_db_vendor_account_{{ $sv['key'] }}" wire:model="bindingForm.vendor_account" class="mt-1 block w-full font-mono text-sm" placeholder="{{ $sv['account_label'] }}" />
                                </div>
                            @endif
                            <div>
                                <x-input-label for="binding_db_vendor_key_{{ $sv['key'] }}" :value="__(':vendor API key', ['vendor' => $sv['label']])" />
                                <x-text-input type="password" id="binding_db_vendor_key_{{ $sv['key'] }}" wire:model="bindingForm.vendor_api_key" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('paste your :vendor API key', ['vendor' => $sv['label']]) }}" autocomplete="new-password" />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Stored encrypted. Leave blank to reuse a key you\'ve already connected.') }}</p>
                            </div>
                        </div>
                    @endforeach

                    <p class="text-xs text-brand-moss" x-show="placement === 'on_box'">{{ __('Creates the database on this site\'s server with generated credentials and injects the connection variables.') }}</p>
                    <p class="text-xs text-brand-moss" x-show="placement === 'managed'" x-cloak>{{ __('Provisions an isolated managed cluster co-located with this server, locks it to your server\'s network, and injects the connection variables once it\'s online (a few minutes). Redeploy to apply.') }}</p>
                    <p class="text-xs text-brand-moss" x-show="placement === 'dedicated_vm'" x-cloak>{{ __('Provisions a new server on your connected provider (same region + private network), installs the engine, and attaches the database once it\'s ready (several minutes). Redeploy to apply.') }}</p>
                </div>
            @elseif ($bindingModalType === 'queue')
                <div>
                    <x-input-label for="binding_queue_driver" :value="__('Queue driver')" />
                    <select id="binding_queue_driver" wire:model="bindingForm.driver" class="dply-input">
                        <option value="database">{{ __('Database') }}</option>
                        <option value="redis">{{ __('Redis') }}</option>
                    </select>
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Sets QUEUE_CONNECTION. Redis requires the Redis binding to be attached too.') }}</p>
                </div>
            @elseif ($bindingModalType === 'cache')
                <div class="space-y-4">
                    <div>
                        <x-input-label for="binding_cache_driver" :value="__('Cache store')" />
                        <select id="binding_cache_driver" wire:model="bindingForm.driver" class="dply-input">
                            <option value="database">{{ __('Database') }}</option>
                            <option value="redis">{{ __('Redis') }}</option>
                            <option value="file">{{ __('File') }}</option>
                            <option value="array">{{ __('Array (no shared cache)') }}</option>
                        </select>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Sets CACHE_STORE. Redis requires the Redis binding to be attached too; database uses the app database.') }}</p>
                    </div>
                    <div>
                        <x-input-label for="binding_cache_prefix" :value="__('Cache prefix (optional)')" />
                        <x-text-input id="binding_cache_prefix" wire:model="bindingForm.prefix" class="mt-1 block w-full font-mono text-sm" placeholder="myapp_cache_" />
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Sets CACHE_PREFIX — namespaces this app\'s cache keys so apps sharing a Redis/Memcached instance don\'t collide. Leave blank for the framework default.') }}</p>
                    </div>
                </div>
            @elseif ($bindingModalType === 'session')
                {{-- Session config binding: each field is optional. Blank = "use
                     default", so only the ones you set are injected as SESSION_*. --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="binding_session_driver" :value="__('Driver')" />
                        <select id="binding_session_driver" wire:model="bindingForm.driver" class="dply-input">
                            <option value="">{{ __('Use default (database)') }}</option>
                            <option value="database">{{ __('Database') }}</option>
                            <option value="file">{{ __('File') }}</option>
                            <option value="cookie">{{ __('Cookie') }}</option>
                            <option value="redis">{{ __('Redis') }}</option>
                            <option value="memcached">{{ __('Memcached') }}</option>
                            <option value="array">{{ __('Array (no persistence)') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="binding_session_lifetime" :value="__('Lifetime (minutes)')" />
                        <x-text-input id="binding_session_lifetime" type="number" min="1" wire:model="bindingForm.lifetime" class="mt-1 block w-full font-mono text-sm" placeholder="120" />
                    </div>
                    <div>
                        <x-input-label for="binding_session_encrypt" :value="__('Encrypt session data')" />
                        <select id="binding_session_encrypt" wire:model="bindingForm.encrypt" class="dply-input">
                            <option value="">{{ __('Use default (false)') }}</option>
                            <option value="true">{{ __('true') }}</option>
                            <option value="false">{{ __('false') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="binding_session_same_site" :value="__('SameSite policy')" />
                        <select id="binding_session_same_site" wire:model="bindingForm.same_site" class="dply-input">
                            <option value="">{{ __('Use default (lax)') }}</option>
                            <option value="lax">{{ __('lax') }}</option>
                            <option value="strict">{{ __('strict') }}</option>
                            <option value="none">{{ __('none') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="binding_session_secure" :value="__('Secure cookie (HTTPS only)')" />
                        <select id="binding_session_secure" wire:model="bindingForm.secure_cookie" class="dply-input">
                            <option value="">{{ __('Use default (false)') }}</option>
                            <option value="true">{{ __('true') }}</option>
                            <option value="false">{{ __('false') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="binding_session_http_only" :value="__('HTTP-only cookie')" />
                        <select id="binding_session_http_only" wire:model="bindingForm.http_only" class="dply-input">
                            <option value="">{{ __('Use default (true)') }}</option>
                            <option value="true">{{ __('true') }}</option>
                            <option value="false">{{ __('false') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="binding_session_path" :value="__('Cookie path')" />
                        <x-text-input id="binding_session_path" wire:model="bindingForm.path" class="mt-1 block w-full font-mono text-sm" placeholder="/" />
                    </div>
                    <div>
                        <x-input-label for="binding_session_domain" :value="__('Cookie domain')" />
                        <x-text-input id="binding_session_domain" wire:model="bindingForm.domain" class="mt-1 block w-full font-mono text-sm" placeholder="{{ __('current host') }}" />
                    </div>
                </div>
                <p class="text-xs text-brand-moss">{{ __('Injects the full session configuration — every SESSION_* key, using the framework default for any field left on "use default". The redis driver needs the Redis resource connected too. Changing the driver, encryption or cookie path/domain signs out active sessions on the next deploy.') }}</p>
            @elseif ($bindingModalType === 'storage' && $bindingModalMode === 'provision')
                @php
                    $osProviders = (array) config('object_storage.providers', []);
                    $osProvisionable = array_filter($osProviders, fn ($p) => (bool) ($p['provision'] ?? false));
                    $osProvider = (string) ($bindingForm['provider'] ?? array_key_first($osProvisionable) ?? '');
                    $osRegions = (array) ($osProviders[$osProvider]['regions'] ?? []);
                    $osApiManaged = (bool) ($osProviders[$osProvider]['api_managed'] ?? false);
                    $osCloudCreds = $osApiManaged ? $this->cloudCredentialsForStorage($osProvider) : [];
                    $osApiMode = $osApiManaged && $osCloudCreds !== [] && ($bindingForm['key_source'] ?? 'manual') === 'api';
                    $osProviderLabel = (string) ($osProviders[$osProvider]['label'] ?? $osProvider);
                    $osPricingNote = (string) ($osProviders[$osProvider]['pricing_note'] ?? '');
                    $osPricingUrl = (string) ($osProviders[$osProvider]['pricing_url'] ?? '');
                @endphp
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-input-label for="binding_storage_provider" :value="__('Provider')" />
                        <select id="binding_storage_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            @foreach ($osProvisionable as $slug => $meta)
                                <option value="{{ $slug }}">{{ $meta['label'] ?? $slug }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Pricing: shown up front, and explicit that dply takes no cut —
                         the provider bills the customer directly on their own account. --}}
                    @if ($osPricingNote !== '' || $osPricingUrl !== '')
                        <div class="sm:col-span-2 rounded-lg border border-brand-ink/10 bg-brand-cream/40 p-3">
                            <div class="flex items-start gap-2">
                                <x-heroicon-o-banknotes class="mt-0.5 h-4 w-4 shrink-0 text-brand-forest" />
                                <div class="min-w-0 text-xs leading-relaxed text-brand-moss">
                                    <p class="font-semibold uppercase tracking-wide text-brand-ink">{{ __('Storage pricing') }}</p>
                                    @if ($osPricingNote !== '')
                                        <p class="mt-0.5">{{ $osPricingNote }}</p>
                                    @endif
                                    <p class="mt-1 font-semibold text-brand-forest">{{ __('dply adds no markup — :provider bills you directly on your own account.', ['provider' => $osProviderLabel]) }}</p>
                                    @if ($osPricingUrl !== '')
                                        <a href="{{ $osPricingUrl }}" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 font-semibold text-brand-forest hover:underline">
                                            {{ __('View :provider pricing', ['provider' => $osProviderLabel]) }}
                                            <x-heroicon-o-arrow-top-right-on-square class="h-3 w-3" />
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif
                    @if ($osApiManaged && $osCloudCreds !== [])
                        {{-- DO can mint the Spaces keys from the cloud API token, so
                             offer fully-automatic vs bring-your-own keys. --}}
                        <div class="sm:col-span-2">
                            <x-input-label :value="__('Keys')" />
                            <div class="mt-1 inline-flex rounded-lg border border-brand-ink/15 bg-brand-sand/30 p-0.5 text-xs font-semibold">
                                <button type="button" wire:click="$set('bindingForm.key_source', 'api')" class="rounded-md px-3 py-1.5 transition-colors {{ $osApiMode ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">
                                    {{ __('Create automatically') }}
                                </button>
                                <button type="button" wire:click="$set('bindingForm.key_source', 'manual')" class="rounded-md px-3 py-1.5 transition-colors {{ ! $osApiMode ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">
                                    {{ __('Use my own keys') }}
                                </button>
                            </div>
                        </div>
                    @endif
                    <div>
                        <x-input-label for="binding_storage_region" :value="__('Region')" />
                        <select id="binding_storage_region" wire:model.live="bindingForm.region" class="dply-input">
                            @foreach ($osRegions as $slug => $label)
                                <option value="{{ $slug }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="binding_storage_bucket" :value="__('New bucket name')" />
                        <x-text-input id="binding_storage_bucket" wire:model="bindingForm.bucket" class="mt-1 block w-full font-mono text-sm" placeholder="my-app-assets" />
                    </div>
                    @include('livewire.sites.settings.partials.environment.storage-disk-field')
                    @if ($osApiMode)
                        @if (count($osCloudCreds) > 1)
                            <div class="sm:col-span-2">
                                <x-input-label for="binding_storage_token" :value="__('DigitalOcean API token')" />
                                <select id="binding_storage_token" wire:model="bindingForm.provider_credential_id" class="dply-input">
                                    @foreach ($osCloudCreds as $cloudCred)
                                        <option value="{{ $cloudCred['id'] }}">{{ $cloudCred['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    @else
                        @include('livewire.sites.settings.partials.environment.storage-credential-fields')
                    @endif
                </div>
                @if ($osApiMode)
                    <p class="text-xs text-brand-moss">{{ __('dply uses your :provider API token to create the Spaces access keys and the bucket — no keys to paste — then injects FILESYSTEM_DISK=s3 and the AWS_* variables at deploy.', ['provider' => __('DigitalOcean')]) }}</p>
                @else
                    <p class="text-xs text-brand-moss">{{ __('dply creates the bucket on your account with these storage keys, then injects FILESYSTEM_DISK=s3 and the AWS_* variables at deploy. Generate the keys in your provider\'s object storage settings (DigitalOcean Spaces keys / Hetzner S3 credentials) — they need permission to create buckets.') }}</p>
                    @if ($osApiManaged && $osCloudCreds === [])
                        <p class="text-xs text-brand-moss">{{ __('Tip: connect a :provider API token under Credentials and dply can create the keys and bucket for you automatically.', ['provider' => $osProviderLabel]) }}</p>
                    @endif
                @endif
            @elseif ($bindingModalType === 'storage')
                @php
                    $osProviders = (array) config('object_storage.providers', []);
                    $osProvider = (string) ($bindingForm['provider'] ?? 'aws_s3');
                    $osRegions = (array) ($osProviders[$osProvider]['regions'] ?? []);
                    $osTemplate = (string) ($osProviders[$osProvider]['endpoint_template'] ?? '');
                    $osRegion = (string) ($bindingForm['region'] ?? '');
                    $osDerivedEndpoint = ($osTemplate !== '' && $osRegion !== '')
                        ? str_replace('{region}', $osRegion, $osTemplate)
                        : '';
                    $osIsCustom = $osProvider === 'custom_s3';
                @endphp
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <x-input-label for="binding_storage_provider" :value="__('Provider')" />
                        <select id="binding_storage_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            @foreach ($osProviders as $slug => $meta)
                                <option value="{{ $slug }}">{{ $meta['label'] ?? $slug }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sm:col-span-2">
                        <x-input-label for="binding_storage_bucket" :value="__('Bucket')" />
                        <x-text-input id="binding_storage_bucket" wire:model="bindingForm.bucket" class="mt-1 block w-full font-mono text-sm" placeholder="my-app-assets" />
                    </div>
                    @include('livewire.sites.settings.partials.environment.storage-disk-field')
                    @include('livewire.sites.settings.partials.environment.storage-credential-fields')
                    <div>
                        <x-input-label for="binding_storage_region" :value="$osIsCustom ? __('Region (optional)') : __('Region')" />
                        @if ($osRegions !== [])
                            <select id="binding_storage_region" wire:model.live="bindingForm.region" class="dply-input">
                                @foreach ($osRegions as $slug => $label)
                                    <option value="{{ $slug }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @else
                            <x-text-input id="binding_storage_region" wire:model="bindingForm.region" class="mt-1 block w-full font-mono text-sm" placeholder="us-east-1" />
                        @endif
                    </div>
                    @if ($osIsCustom)
                        <div>
                            <x-input-label for="binding_storage_endpoint" :value="__('Endpoint')" />
                            <x-text-input id="binding_storage_endpoint" wire:model="bindingForm.endpoint" class="mt-1 block w-full font-mono text-sm" placeholder="https://s3.example.com" />
                        </div>
                    @endif
                    <div class="{{ $osIsCustom ? '' : 'sm:col-span-2' }}">
                        <x-input-label for="binding_storage_url" :value="__('Public URL (optional)')" />
                        <x-text-input id="binding_storage_url" wire:model="bindingForm.url" class="mt-1 block w-full font-mono text-sm" placeholder="https://cdn.example.com" />
                    </div>
                </div>
                @if (! $osIsCustom && $osDerivedEndpoint !== '')
                    <p class="text-xs text-brand-moss">{{ __('Endpoint :endpoint is set automatically for this provider and region.', ['endpoint' => $osDerivedEndpoint]) }}</p>
                @elseif ($osIsCustom)
                    <p class="text-xs text-brand-moss">{{ __('Custom S3 storage needs the endpoint of your provider (path-style or virtual-hosted).') }}</p>
                @endif
                <p class="text-xs text-brand-moss">{{ __('Injects the AWS_* connection variables at deploy (FILESYSTEM_DISK=s3 for the default disk; namespaced AWS_<DISK>_* for additional disks).') }}</p>
            @elseif ($bindingModalType === 'logging')
                @php $logProvider = (string) ($bindingForm['provider'] ?? 'papertrail'); @endphp
                <div class="space-y-4">
                    <div>
                        <x-input-label for="binding_log_provider" :value="__('Provider')" />
                        <select id="binding_log_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            <option value="papertrail">{{ __('Papertrail') }}</option>
                            <option value="logtail">{{ __('Logtail / Better Stack') }}</option>
                            <option value="syslog">{{ __('Generic syslog') }}</option>
                            <option value="dply_realtime">{{ __('dply Realtime (managed)') }}</option>
                        </select>
                    </div>
                    @if ($logProvider === 'dply_realtime')
                        <p class="text-xs text-brand-moss">{{ __('dply provides the logging endpoint — no credentials needed. Injects LOG_CHANNEL=papertrail pointing at the dply-managed syslog endpoint.') }}</p>
                    @else
                        @include('livewire.sites.settings.partials.environment.log-drain-credential-fields', ['logProvider' => $logProvider])
                        <p class="text-xs text-brand-moss">
                            @if ($logProvider === 'papertrail')
                                {{ __('Injects LOG_CHANNEL=papertrail and PAPERTRAIL_URL / PAPERTRAIL_PORT at deploy.') }}
                            @elseif ($logProvider === 'logtail')
                                {{ __('Injects LOG_CHANNEL=stack, LOG_STACK=single,logtail, and LOGTAIL_SOURCE_TOKEN at deploy.') }}
                            @elseif ($logProvider === 'syslog')
                                {{ __('Injects LOG_CHANNEL=syslog at deploy.') }}
                            @endif
                        </p>
                    @endif
                </div>
            @elseif ($bindingModalType === 'error_tracking')
                @php $etProvider = (string) ($bindingForm['provider'] ?? 'sentry'); @endphp
                <div class="space-y-4">
                    <div>
                        <x-input-label for="binding_et_provider" :value="__('Provider')" />
                        <select id="binding_et_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            <option value="sentry">{{ __('Sentry') }}</option>
                            <option value="bugsnag">{{ __('Bugsnag') }}</option>
                            <option value="flare">{{ __('Flare') }}</option>
                            <option value="lookout">{{ __('Lookout') }}</option>
                        </select>
                    </div>

                    @if ($etProvider === 'lookout')
                        @php
                            $lkMode = (string) ($bindingForm['lookout_mode'] ?? 'provision');
                            $lkManaged = config('services.lookout.account_model') === 'managed';
                        @endphp
                        <div class="flex gap-2">
                            <button type="button" wire:click="$set('bindingForm.lookout_mode', 'provision')"
                                class="flex-1 rounded-lg border px-3 py-2 text-xs font-semibold transition {{ $lkMode === 'provision' ? 'border-brand-sage bg-brand-sage/10 text-brand-pine' : 'border-brand-mist text-brand-moss hover:border-brand-sage/60' }}">
                                {{ __('Create a project') }}
                            </button>
                            <button type="button" wire:click="$set('bindingForm.lookout_mode', 'attach')"
                                class="flex-1 rounded-lg border px-3 py-2 text-xs font-semibold transition {{ $lkMode === 'attach' ? 'border-brand-sage bg-brand-sage/10 text-brand-pine' : 'border-brand-mist text-brand-moss hover:border-brand-sage/60' }}">
                                {{ __('Use an existing DSN') }}
                            </button>
                        </div>

                        @if ($lkMode === 'provision')
                            @if ($lkManaged)
                                <div class="rounded-lg border border-brand-sage/40 bg-brand-sage/5 px-4 py-3 text-xs text-brand-pine">
                                    {{ __('dply manages the Lookout account — just name the project and we create it for you.') }}
                                </div>
                                <div>
                                    <x-input-label for="binding_lk_name" :value="__('Project name')" />
                                    <x-text-input id="binding_lk_name" wire:model="bindingForm.project_name" class="w-full" />
                                </div>
                            @else
                                <div>
                                    <x-input-label for="binding_lk_token" :value="__('Lookout API token')" />
                                    <x-text-input id="binding_lk_token" type="password" wire:model="bindingForm.lookout_token" class="w-full" placeholder="lk_…" autocomplete="off" />
                                    <p class="mt-1 text-xs text-brand-moss">{{ __('From uselookout.app → Settings → API tokens. Stored encrypted and reused across sites in this organization.') }}</p>
                                </div>
                                <div>
                                    <div class="flex items-end justify-between gap-2">
                                        <x-input-label for="binding_lk_org" :value="__('Lookout organization')" />
                                        <button type="button" wire:click="loadLookoutOrganizations" wire:target="loadLookoutOrganizations" wire:loading.attr="disabled"
                                            class="text-xs font-semibold text-brand-sage hover:text-brand-pine disabled:opacity-50">
                                            <span wire:loading.remove wire:target="loadLookoutOrganizations">{{ __('Load my organizations') }}</span>
                                            <span wire:loading wire:target="loadLookoutOrganizations">{{ __('Loading…') }}</span>
                                        </button>
                                    </div>
                                    @if (count($lookoutOrganizations) > 0)
                                        <select id="binding_lk_org" wire:model="bindingForm.lookout_org" class="dply-input">
                                            <option value="">{{ __('Select an organization…') }}</option>
                                            @foreach ($lookoutOrganizations as $lkOrg)
                                                <option value="{{ $lkOrg['id'] }}">{{ $lkOrg['name'] }}</option>
                                            @endforeach
                                        </select>
                                    @else
                                        <x-text-input id="binding_lk_org" wire:model="bindingForm.lookout_org" class="w-full" placeholder="01J…" autocomplete="off" />
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('Enter the organization ID, or load them from your token above.') }}</p>
                                    @endif
                                </div>
                                <div>
                                    <x-input-label for="binding_lk_name" :value="__('Project name')" />
                                    <x-text-input id="binding_lk_name" wire:model="bindingForm.project_name" class="w-full" />
                                </div>
                            @endif
                        @else
                            <div>
                                <x-input-label for="binding_lk_dsn" :value="__('Lookout DSN')" />
                                <x-text-input id="binding_lk_dsn" wire:model="bindingForm.dsn" class="w-full" placeholder="https://&lt;key&gt;&#64;uselookout.app" autocomplete="off" />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Paste the ingest DSN from an existing Lookout project.') }}</p>
                            </div>
                        @endif
                    @else
                        @include('livewire.sites.settings.partials.environment.error-tracking-credential-fields', ['etProvider' => $etProvider])
                    @endif

                    @php $etPackage = \App\Modules\Deploy\Services\SiteBindingManager::ERROR_TRACKING_PACKAGES[$etProvider] ?? null; @endphp
                    @if ($etProvider === 'lookout')
                        <div class="rounded-lg border border-brand-sage/40 bg-brand-sage/5 px-4 py-3 text-xs text-brand-pine">
                            {{ __('dply runs') }} <code class="font-mono font-semibold">composer require lookout/tracing</code> {{ __('on the server for you, then injects LOOKOUT_DSN at deploy.') }}
                        </div>
                    @elseif ($etPackage)
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                            {{ __('Requires the') }} <code class="font-mono font-semibold">{{ $etPackage }}</code> {{ __('package. Add it to your') }} <code class="font-mono font-semibold">composer.json</code> {{ __('before deploying.') }}
                        </div>
                    @endif
                    <p class="text-xs text-brand-moss">
                        @if ($etProvider === 'sentry')
                            {{ __('Injects SENTRY_LARAVEL_DSN (and SENTRY_TRACES_SAMPLE_RATE when set) at deploy.') }}
                        @elseif ($etProvider === 'bugsnag')
                            {{ __('Injects BUGSNAG_API_KEY at deploy.') }}
                        @elseif ($etProvider === 'flare')
                            {{ __('Injects FLARE_KEY at deploy. Flare ships with Laravel via spatie/laravel-ignition.') }}
                        @elseif ($etProvider === 'lookout')
                            {{ __('Injects LOOKOUT_DSN + LOOKOUT_LARAVEL=true so the app reports errors, traces and logs to Lookout.') }}
                        @endif
                    </p>
                </div>
            @elseif ($bindingModalType === 'ai')
                @php $aiProvider = (string) ($bindingForm['provider'] ?? 'openai'); @endphp
                <div class="space-y-4">
                    <div>
                        <x-input-label for="binding_ai_provider" :value="__('Provider')" />
                        <select id="binding_ai_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            <option value="openai">{{ __('OpenAI') }}</option>
                            <option value="anthropic">{{ __('Anthropic') }}</option>
                            <option value="gemini">{{ __('Google Gemini') }}</option>
                            <option value="groq">{{ __('Groq') }}</option>
                            <option value="mistral">{{ __('Mistral') }}</option>
                        </select>
                    </div>
                    @include('livewire.sites.settings.partials.environment.ai-credential-fields', ['aiProvider' => $aiProvider])
                    <p class="text-xs text-brand-moss">
                        {{ __('Injects') }} <code class="font-mono">{{ \App\Modules\Deploy\Services\SiteBindingManager::AI_KEY_ENV[$aiProvider] ?? 'OPENAI_API_KEY' }}</code>
                        @if ($aiProvider === 'openai') {{ __('(and OPENAI_ORGANIZATION when set)') }} @endif
                        {{ __('at deploy.') }}
                    </p>
                </div>
            @elseif ($bindingModalType === 'captcha')
                @php $captchaProvider = (string) ($bindingForm['provider'] ?? 'turnstile'); @endphp
                <div class="space-y-4">
                    <div>
                        <x-input-label for="binding_captcha_provider" :value="__('Provider')" />
                        <select id="binding_captcha_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            <option value="turnstile">{{ __('Cloudflare Turnstile') }}</option>
                            <option value="recaptcha">{{ __('Google reCAPTCHA') }}</option>
                            <option value="hcaptcha">{{ __('hCaptcha') }}</option>
                        </select>
                    </div>
                    @include('livewire.sites.settings.partials.environment.captcha-credential-fields', ['captchaProvider' => $captchaProvider])
                    <p class="text-xs text-brand-moss">{{ __('Injects the site key + secret, plus a VITE_ mirror of the public site key for the browser bundle. The secret stays server-only.') }}</p>
                </div>
            @elseif ($bindingModalType === 'sms')
                @php $smsProvider = (string) ($bindingForm['provider'] ?? 'twilio'); @endphp
                <div class="space-y-4">
                    <div>
                        <x-input-label for="binding_sms_provider" :value="__('Provider')" />
                        <select id="binding_sms_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            <option value="twilio">{{ __('Twilio') }}</option>
                            <option value="vonage">{{ __('Vonage') }}</option>
                            <option value="fcm">{{ __('Firebase Cloud Messaging') }}</option>
                        </select>
                    </div>
                    @include('livewire.sites.settings.partials.environment.sms-credential-fields', ['smsProvider' => $smsProvider])
                    <p class="text-xs text-brand-moss">
                        @if ($smsProvider === 'twilio')
                            {{ __('Injects TWILIO_SID, TWILIO_AUTH_TOKEN and TWILIO_FROM at deploy.') }}
                        @elseif ($smsProvider === 'vonage')
                            {{ __('Injects VONAGE_KEY, VONAGE_SECRET and VONAGE_SMS_FROM at deploy.') }}
                        @elseif ($smsProvider === 'fcm')
                            {{ __('Injects FCM_SERVER_KEY at deploy.') }}
                        @endif
                    </p>
                </div>
            @elseif ($bindingModalType === 'search')
                @php $searchProvider = (string) ($bindingForm['provider'] ?? 'meilisearch'); @endphp
                <div class="space-y-4">
                    <div>
                        <x-input-label for="binding_search_provider" :value="__('Driver')" />
                        <select id="binding_search_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            <option value="meilisearch">{{ __('Meilisearch') }}</option>
                            <option value="typesense">{{ __('Typesense') }}</option>
                            <option value="algolia">{{ __('Algolia') }}</option>
                        </select>
                    </div>
                    @include('livewire.sites.settings.partials.environment.search-credential-fields', ['searchProvider' => $searchProvider])
                    @php $searchPackage = \App\Modules\Deploy\Services\SiteBindingManager::SEARCH_PACKAGES[$searchProvider] ?? null; @endphp
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                        {{ __('Requires') }} <code class="font-mono font-semibold">laravel/scout</code>@if ($searchPackage) {{ __(' and ') }}<code class="font-mono font-semibold">{{ $searchPackage }}</code>@endif. {{ __('Add to composer.json before deploying.') }}
                    </div>
                    @if (in_array($searchProvider, ['meilisearch', 'typesense'], true))
                        <p class="text-xs text-brand-moss">{{ __('Point this at a Meilisearch/Typesense endpoint you run (on this server\'s loopback/private network, or hosted). On-server provisioning is coming soon.') }}</p>
                    @endif
                </div>
            @elseif ($bindingModalType === 'payments')
                @php
                    $paymentsProvider = (string) ($bindingForm['provider'] ?? 'stripe');
                    $webhookPreview = $this->paymentsWebhookPreview($paymentsProvider);
                @endphp
                <div class="space-y-4">
                    <div>
                        <x-input-label for="binding_payments_provider" :value="__('Provider')" />
                        <select id="binding_payments_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            <option value="stripe">{{ __('Stripe') }}</option>
                            <option value="paddle">{{ __('Paddle') }}</option>
                        </select>
                    </div>
                    @include('livewire.sites.settings.partials.environment.payments-credential-fields', ['paymentsProvider' => $paymentsProvider])
                    @if ($webhookPreview)
                        <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-xs text-brand-moss">
                            <p class="font-semibold text-brand-ink">{{ __('Webhook endpoint') }}</p>
                            <p class="mt-1">{{ __('Register this URL in your :provider dashboard:', ['provider' => ucfirst($paymentsProvider)]) }}</p>
                            <code class="mt-1 block break-all font-mono text-brand-ink">{{ $webhookPreview }}</code>
                        </div>
                    @else
                        <p class="text-xs text-amber-700">{{ __('Add a primary domain to this site to get a webhook endpoint URL.') }}</p>
                    @endif
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                        {{ __('Requires') }} <code class="font-mono font-semibold">laravel/cashier{{ $paymentsProvider === 'paddle' ? '-paddle' : '' }}</code>. {{ __('Add to composer.json before deploying.') }}
                    </div>
                </div>
            @elseif ($bindingModalType === 'oauth')
                @php
                    $oauthProvider = (string) ($bindingForm['provider'] ?? 'github');
                    $redirectPreview = $this->oauthRedirectPreview($oauthProvider);
                @endphp
                <div class="space-y-4">
                    <div>
                        <x-input-label for="binding_oauth_provider" :value="__('Provider')" />
                        <select id="binding_oauth_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            <option value="github">{{ __('GitHub') }}</option>
                            <option value="google">{{ __('Google') }}</option>
                            <option value="facebook">{{ __('Facebook') }}</option>
                            <option value="gitlab">{{ __('GitLab') }}</option>
                            <option value="linkedin">{{ __('LinkedIn') }}</option>
                        </select>
                    </div>
                    @include('livewire.sites.settings.partials.environment.oauth-credential-fields', ['oauthProvider' => $oauthProvider])
                    <div class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-xs text-brand-moss">
                        <p class="font-semibold text-brand-ink">{{ __('Redirect / callback URL') }}</p>
                        @if ($redirectPreview)
                            <p class="mt-1">{{ __('Auto-filled from this site — paste it into the provider\'s OAuth app:') }}</p>
                            <code class="mt-1 block break-all font-mono text-brand-ink">{{ $redirectPreview }}</code>
                        @else
                            <p class="mt-1 text-amber-700">{{ __('Add a primary domain (or enter a redirect URL below) so the callback URL can be derived.') }}</p>
                        @endif
                    </div>
                    <p class="text-xs text-brand-moss">{{ __('Injects :p_CLIENT_ID, :p_CLIENT_SECRET and :p_REDIRECT_URI at deploy.', ['p' => strtoupper($oauthProvider)]) }}</p>
                </div>
            @elseif ($bindingModalType === 'redis')
                @php
                    $existingCacheService = \App\Models\ServerCacheService::query()
                        ->where('server_id', $site->server_id)
                        ->whereIn('engine', ['redis', 'valkey', 'keydb', 'dragonfly'])
                        ->first();
                    $valkeyAvailable = \App\Support\Servers\CacheEngineAvailability::isAvailable('valkey');
                @endphp
                <div>
                    <x-input-label for="binding_redis_target" :value="__('Redis service')" />
                    <x-binding-target-select
                        id="binding_redis_target"
                        model="bindingForm.target_id"
                        :targets="$bindingTargets"
                        :selected="$bindingForm['target_id'] ?? ''"
                        :placeholder="__('Choose a Redis service…')"
                    />
                    @if ($bindingTargets === [])
                        <p class="mt-2 text-xs text-brand-moss">{{ __('No Redis-compatible service is reachable on this server or its private network peers.') }}</p>
                        @if ($existingCacheService === null)
                            {{-- Nothing installed — offer to install. --}}
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" wire:click="installCacheOnServer('redis')" wire:loading.attr="disabled" wire:target="installCacheOnServer" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-60">
                                    <x-heroicon-o-bolt class="h-4 w-4" />
                                    {{ __('Install Redis') }}
                                </button>
                                @if ($valkeyAvailable)
                                    <button type="button" wire:click="installCacheOnServer('valkey')" wire:loading.attr="disabled" wire:target="installCacheOnServer" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                                        <x-heroicon-o-bolt class="h-4 w-4" />
                                        {{ __('Install Valkey') }}
                                    </button>
                                @endif
                            </div>
                        @else
                            {{-- Something installed but not reachable — offer to switch engines. --}}
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Currently installed: :engine. You can switch to a different engine.', ['engine' => ucfirst($existingCacheService->engine)]) }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach (['redis', 'valkey'] as $altEngine)
                                    @if ($altEngine !== $existingCacheService->engine && ($altEngine === 'redis' || $valkeyAvailable))
                                        <button type="button" wire:click="switchCacheOnServer('{{ $altEngine }}')" wire:loading.attr="disabled" wire:target="switchCacheOnServer" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                                            <x-heroicon-o-arrows-right-left class="h-4 w-4" />
                                            {{ __('Switch to :engine', ['engine' => ucfirst($altEngine)]) }}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Grouped by location: Redis-family services on this server (loopback) and on private-network peers (private IP). Each option shows how many other apps already use it — sharing one instance means a shared keyspace, so set a prefix to isolate this app. Injects REDIS_HOST / REDIS_PORT / REDIS_CLIENT (plus password and prefix when set) at deploy.') }}</p>
                        @if ($existingCacheService !== null && in_array($existingCacheService->engine, ['redis', 'valkey'], true))
                            <div class="mt-3 border-t border-brand-ink/10 pt-3">
                                <p class="text-[11px] text-brand-mist">{{ __('Want a different engine?') }}</p>
                                <div class="mt-1.5 flex flex-wrap gap-2">
                                    @foreach (['redis', 'valkey'] as $altEngine)
                                        @if ($altEngine !== $existingCacheService->engine && ($altEngine === 'redis' || $valkeyAvailable))
                                            <button type="button" wire:click="switchCacheOnServer('{{ $altEngine }}')" wire:loading.attr="disabled" wire:target="switchCacheOnServer" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                                                <x-heroicon-o-arrows-right-left class="h-4 w-4" />
                                                {{ __('Switch to :engine', ['engine' => ucfirst($altEngine)]) }}
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{-- One-click: also point cache, sessions, and the queue at
                             this Redis. Creates the cache/queue/session driver
                             bindings (redis) so the app uses Redis everywhere
                             without three more trips through the modal. Existing
                             driver bindings are preserved server-side. --}}
                        <label class="mt-4 flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">
                            <input type="checkbox" wire:model="bindingForm.use_for_drivers" class="mt-0.5 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            <span>
                                <span class="block text-sm font-medium text-brand-ink">{{ __('Use Redis for cache, sessions, and the queue') }}</span>
                                {{ __('Sets CACHE_STORE, SESSION_DRIVER, and QUEUE_CONNECTION to redis. Cache/session/queue you\'ve already configured are left untouched — repoint each one anytime.') }}
                            </span>
                        </label>
                    @endif
                </div>
            @elseif ($bindingModalType === 'mail')
                @php
                    $mailProvider = (string) ($bindingForm['provider'] ?? 'smtp');
                    $mailIsChain = in_array($mailProvider, ['failover', 'roundrobin'], true);
                @endphp
                <div class="space-y-4">
                    <div>
                        <x-input-label for="binding_mail_provider" :value="__('Provider')" />
                        <select id="binding_mail_provider" wire:model.live="bindingForm.provider" class="dply-input">
                            <option value="smtp">{{ __('SMTP (any host)') }}</option>
                            <option value="mailgun">{{ __('Mailgun') }}</option>
                            <option value="postmark">{{ __('Postmark') }}</option>
                            <option value="ses">{{ __('Amazon SES') }}</option>
                            <option value="resend">{{ __('Resend') }}</option>
                            <option value="sendgrid">{{ __('SendGrid') }}</option>
                            <option value="cloudflare">{{ __('Cloudflare') }}</option>
                            <option value="log">{{ __('Log (no delivery — dev/staging)') }}</option>
                            <option value="failover">{{ __('Failover (primary + backups)') }}</option>
                            <option value="roundrobin">{{ __('Round-robin (load balance)') }}</option>
                        </select>
                    </div>

                    @if ($mailIsChain)
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="binding_mail_from" :value="__('From address')" />
                                <x-text-input id="binding_mail_from" type="email" wire:model="bindingForm.from_address" class="mt-1 block w-full font-mono text-sm" placeholder="hello@example.com" />
                            </div>
                            <div>
                                <x-input-label for="binding_mail_fromname" :value="__('From name (optional)')" />
                                <x-text-input id="binding_mail_fromname" wire:model="bindingForm.from_name" class="mt-1 block w-full text-sm" placeholder="{{ $site->name }}" />
                            </div>
                        </div>

                        <div>
                            <x-input-label :value="$mailProvider === 'failover' ? __('Mailers (tried in order, top first)') : __('Mailers (load balanced)')" />
                            <div class="mt-2 space-y-3">
                                @foreach (($bindingForm['legs'] ?? []) as $legIndex => $leg)
                                    @include('livewire.sites.settings.partials.environment.mail-leg-fields', ['legIndex' => $legIndex])
                                @endforeach
                            </div>
                            <button type="button" wire:click="addMailLeg" class="mt-3 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                <x-heroicon-o-plus class="h-4 w-4" /> {{ __('Add mailer') }}
                            </button>
                        </div>

                        {{-- The chain ORDER can't be injected via env — it lives in
                             config/mail.php. Show the exact snippet to paste. --}}
                        <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                            <p class="font-semibold">{{ __('One-time app change required') }}</p>
                            <p class="mt-1">{{ __('dply injects MAIL_MAILER and every mailer\'s credentials, but the chain order must be defined in your app\'s config/mail.php. Add (or merge) this:') }}</p>
                            <pre class="mt-2 overflow-x-auto rounded-md bg-white/70 p-3 font-mono text-[11px] leading-relaxed text-brand-ink">{{ $this->mailFailoverSnippet($mailProvider, $bindingForm['legs'] ?? []) }}</pre>
                        </div>
                        <p class="text-xs text-brand-moss">{{ __('Sets MAIL_MAILER=:t and injects each mailer\'s credentials + the from-address at deploy. A chain can include at most one SMTP mailer.', ['t' => $mailProvider]) }}</p>
                    @elseif ($mailProvider !== 'log')
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="binding_mail_from" :value="__('From address')" />
                                <x-text-input id="binding_mail_from" type="email" wire:model="bindingForm.from_address" class="mt-1 block w-full font-mono text-sm" placeholder="hello@example.com" />
                            </div>
                            <div>
                                <x-input-label for="binding_mail_fromname" :value="__('From name (optional)')" />
                                <x-text-input id="binding_mail_fromname" wire:model="bindingForm.from_name" class="mt-1 block w-full text-sm" placeholder="{{ $site->name }}" />
                            </div>
                        </div>

                        @include('livewire.sites.settings.partials.environment.mail-credential-fields', ['mailProvider' => $mailProvider])

                        <p class="text-xs text-brand-moss">
                            @if ($mailProvider === 'smtp')
                                {{ __('Injects MAIL_MAILER=smtp, MAIL_HOST / MAIL_PORT, MAIL_USERNAME / MAIL_PASSWORD, MAIL_ENCRYPTION, and the from-address at deploy.') }}
                            @elseif ($mailProvider === 'mailgun')
                                {{ __('Injects MAIL_MAILER=mailgun, MAILGUN_DOMAIN / MAILGUN_SECRET / MAILGUN_ENDPOINT, and the from-address at deploy.') }}
                            @elseif ($mailProvider === 'postmark')
                                {{ __('Injects MAIL_MAILER=postmark, POSTMARK_TOKEN, and the from-address at deploy.') }}
                            @elseif ($mailProvider === 'ses')
                                {{ __('Injects MAIL_MAILER=ses, the AWS_* credentials, and the from-address at deploy.') }}
                            @elseif ($mailProvider === 'resend')
                                {{ __('Injects MAIL_MAILER=resend, RESEND_KEY, and the from-address at deploy.') }}
                            @elseif ($mailProvider === 'sendgrid')
                                {{ __('Injects MAIL_MAILER=sendgrid, SENDGRID_API_KEY, and the from-address at deploy.') }}
                            @elseif ($mailProvider === 'cloudflare')
                                {{ __('Injects MAIL_MAILER=cloudflare, CLOUDFLARE_ACCOUNT_ID / CLOUDFLARE_KEY, and the from-address at deploy.') }}
                            @endif
                        </p>
                    @else
                        <p class="text-xs text-brand-moss">{{ __('Injects MAIL_MAILER=log — mail is written to the application log instead of being delivered. Useful for dev/staging.') }}</p>
                    @endif
                </div>
            @elseif ($bindingModalType === 'broadcasting')
                @php
                    // The managed (dply-hosted, billed) relay path is gated behind
                    // surface.realtime. When off, only bring-your-own is offered.
                    $bcManagedEnabled = \Laravel\Pennant\Feature::active('surface.realtime');
                    $bcKind = $bcManagedEnabled ? (string) ($bindingForm['kind'] ?? 'managed') : 'byo';
                    $bcProvision = (bool) ($bindingForm['provision'] ?? false);
                    $bcDriver = (string) ($bindingForm['driver'] ?? 'pusher');
                    $bcTiers = $this->broadcastingTiers();
                    $bcTier = (string) ($bindingForm['tier'] ?? array_key_first($bcTiers));
                    $bcTierPrice = number_format((($bcTiers[$bcTier]['price_cents'] ?? 0) / 100), 2);
                @endphp
                <div class="space-y-4">
                    {{-- Managed (dply relay) vs bring-your-own. The managed relay
                         toggle only shows when surface.realtime is enabled; BYO is
                         always available. Self-hosted Reverb on the VM is
                         orchestrated separately (coming next). --}}
                    @if ($bcManagedEnabled)
                        <div class="inline-flex rounded-lg border border-brand-ink/15 bg-brand-sand/30 p-0.5 text-xs font-semibold">
                            <button type="button" wire:click="$set('bindingForm.kind', 'managed')" class="rounded-md px-3 py-1.5 transition-colors {{ $bcKind === 'managed' ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">
                                {{ __('dply realtime') }}
                            </button>
                            <button type="button" wire:click="$set('bindingForm.kind', 'byo')" class="rounded-md px-3 py-1.5 transition-colors {{ $bcKind === 'byo' ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">
                                {{ __('Bring your own') }}
                            </button>
                        </div>
                    @endif

                    @if ($bcKind === 'managed')
                        {{-- Attach an existing app (share across sites) vs provision
                             a new, billed one. --}}
                        <div class="inline-flex rounded-lg border border-brand-ink/15 bg-brand-sand/30 p-0.5 text-xs font-semibold">
                            <button type="button" wire:click="$set('bindingForm.provision', false)" class="rounded-md px-3 py-1.5 transition-colors {{ ! $bcProvision ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">
                                {{ __('Attach existing') }}
                            </button>
                            <button type="button" wire:click="$set('bindingForm.provision', true)" class="rounded-md px-3 py-1.5 transition-colors {{ $bcProvision ? 'bg-white text-brand-ink shadow-sm' : 'text-brand-moss hover:text-brand-ink' }}">
                                {{ __('Provision new') }}
                            </button>
                        </div>

                        @if (! $bcProvision)
                            <div>
                                <x-input-label for="binding_bc_app" :value="__('Broadcasting app')" />
                                <x-binding-target-select
                                    id="binding_bc_app"
                                    model="bindingForm.realtime_app_id"
                                    :targets="$bindingTargets"
                                    :selected="$bindingForm['realtime_app_id'] ?? ''"
                                    :placeholder="__('Choose an app…')"
                                />
                                @if ($bindingTargets === [])
                                    <p class="mt-2 text-xs text-brand-moss">{{ __('No managed broadcasting apps yet — provision a new one.') }}</p>
                                    <button type="button" wire:click="$set('bindingForm.provision', true)" class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                                        <x-heroicon-o-plus class="h-4 w-4" />
                                        {{ __('Provision new app') }}
                                    </button>
                                @else
                                    <p class="mt-2 text-xs text-brand-moss">{{ __('Attaching an existing app reuses it (and its existing charge) across sites — each option shows how many other apps already share it. Injects BROADCAST_CONNECTION=pusher and the PUSHER_* + VITE_PUSHER_* variables at deploy.') }}</p>
                                @endif
                            </div>
                        @else
                            <div>
                                <x-input-label for="binding_bc_name" :value="__('App name (optional)')" />
                                <x-text-input id="binding_bc_name" wire:model="bindingForm.app_name" class="mt-1 block w-full text-sm" placeholder="{{ $site->name }}" />
                            </div>
                            <div>
                                <x-input-label :value="__('Connection tier')" />
                                <div class="mt-1 grid gap-2 sm:grid-cols-3">
                                    @foreach ($bcTiers as $slug => $tier)
                                        <button type="button" wire:click="$set('bindingForm.tier', '{{ $slug }}')" class="rounded-lg border p-3 text-left transition-colors {{ $bcTier === $slug ? 'border-brand-forest bg-brand-forest/5 ring-1 ring-brand-forest/40' : 'border-brand-ink/10 hover:bg-brand-sand/30' }}">
                                            <div class="text-sm font-semibold text-brand-ink">{{ $tier['label'] }}</div>
                                            <div class="mt-0.5 text-[11px] text-brand-moss">{{ number_format($tier['max_connections']) }} {{ __('connections') }}</div>
                                            <div class="mt-1 text-xs font-semibold text-brand-forest">${{ number_format($tier['price_cents'] / 100, 2) }}/{{ __('mo') }}</div>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                            <label class="flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2.5 text-xs text-brand-moss">
                                <input type="checkbox" wire:model="bindingForm.confirm_charge" class="mt-0.5 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span>{{ __('I understand this provisions a managed broadcasting app and adds $:price/mo to this workspace\'s bill.', ['price' => $bcTierPrice]) }}</span>
                            </label>
                            <p class="text-xs text-brand-moss">{{ __('dply provisions the app on the Cloudflare relay and injects BROADCAST_CONNECTION=pusher plus the PUSHER_* + VITE_PUSHER_* variables at deploy.') }}</p>
                        @endif
                    @else
                        {{-- Bring your own: free-form driver + credentials. --}}
                        <div>
                            <x-input-label for="binding_bc_driver" :value="__('Driver')" />
                            <select id="binding_bc_driver" wire:model.live="bindingForm.driver" class="dply-input">
                                <option value="pusher">{{ __('Pusher (Pusher Cloud or self-hosted Reverb)') }}</option>
                                <option value="reverb">{{ __('Reverb (native variables)') }}</option>
                                <option value="ably">{{ __('Ably') }}</option>
                                <option value="log">{{ __('Log (no broadcast — dev/staging)') }}</option>
                                <option value="null">{{ __('Null (disabled)') }}</option>
                            </select>
                        </div>

                        @if ($bcDriver === 'pusher')
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="binding_bc_p_id" :value="__('App ID')" />
                                    <x-text-input id="binding_bc_p_id" wire:model="bindingForm.pusher_app_id" class="mt-1 block w-full font-mono text-sm" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_p_key" :value="__('App key')" />
                                    <x-text-input id="binding_bc_p_key" wire:model="bindingForm.pusher_app_key" class="mt-1 block w-full font-mono text-sm" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_p_secret" :value="__('App secret')" />
                                    <x-text-input id="binding_bc_p_secret" type="password" wire:model="bindingForm.pusher_app_secret" class="mt-1 block w-full font-mono text-sm" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_p_cluster" :value="__('Cluster (Pusher Cloud)')" />
                                    <x-text-input id="binding_bc_p_cluster" wire:model="bindingForm.pusher_cluster" class="mt-1 block w-full font-mono text-sm" placeholder="mt1" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_p_host" :value="__('Host (self-hosted, optional)')" />
                                    <x-text-input id="binding_bc_p_host" wire:model="bindingForm.pusher_host" class="mt-1 block w-full font-mono text-sm" placeholder="ws.example.com" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_p_port" :value="__('Port (optional)')" />
                                    <x-text-input id="binding_bc_p_port" wire:model="bindingForm.pusher_port" class="mt-1 block w-full font-mono text-sm" placeholder="443" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_p_scheme" :value="__('Scheme')" />
                                    <select id="binding_bc_p_scheme" wire:model="bindingForm.pusher_scheme" class="dply-input">
                                        <option value="https">https</option>
                                        <option value="http">http</option>
                                    </select>
                                </div>
                            </div>
                            <p class="text-xs text-brand-moss">{{ __('Injects BROADCAST_CONNECTION=pusher, the PUSHER_* variables, and the VITE_PUSHER_* mirror (key/host/port/scheme/cluster — never the secret) for Laravel Echo.') }}</p>
                        @elseif ($bcDriver === 'reverb')
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <x-input-label for="binding_bc_r_id" :value="__('App ID')" />
                                    <x-text-input id="binding_bc_r_id" wire:model="bindingForm.reverb_app_id" class="mt-1 block w-full font-mono text-sm" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_r_key" :value="__('App key')" />
                                    <x-text-input id="binding_bc_r_key" wire:model="bindingForm.reverb_app_key" class="mt-1 block w-full font-mono text-sm" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_r_secret" :value="__('App secret')" />
                                    <x-text-input id="binding_bc_r_secret" type="password" wire:model="bindingForm.reverb_app_secret" class="mt-1 block w-full font-mono text-sm" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_r_host" :value="__('Host')" />
                                    <x-text-input id="binding_bc_r_host" wire:model="bindingForm.reverb_host" class="mt-1 block w-full font-mono text-sm" placeholder="ws.example.com" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_r_port" :value="__('Port (optional)')" />
                                    <x-text-input id="binding_bc_r_port" wire:model="bindingForm.reverb_port" class="mt-1 block w-full font-mono text-sm" placeholder="443" />
                                </div>
                                <div>
                                    <x-input-label for="binding_bc_r_scheme" :value="__('Scheme')" />
                                    <select id="binding_bc_r_scheme" wire:model="bindingForm.reverb_scheme" class="dply-input">
                                        <option value="https">https</option>
                                        <option value="http">http</option>
                                    </select>
                                </div>
                            </div>
                            <p class="text-xs text-brand-moss">{{ __('Injects BROADCAST_CONNECTION=reverb, the REVERB_* variables, and the VITE_REVERB_* mirror for Laravel Echo.') }}</p>
                        @elseif ($bcDriver === 'ably')
                            <div>
                                <x-input-label for="binding_bc_ably" :value="__('Ably key')" />
                                <x-text-input id="binding_bc_ably" type="password" wire:model="bindingForm.ably_key" class="mt-1 block w-full font-mono text-sm" placeholder="xVLyHw.abc123:..." />
                            </div>
                            <p class="text-xs text-brand-moss">{{ __('Injects BROADCAST_CONNECTION=ably and ABLY_KEY at deploy.') }}</p>
                        @else
                            <p class="text-xs text-brand-moss">{{ __('Sets BROADCAST_CONNECTION only — no broadcaster credentials. Use for dev/staging or to disable broadcasting.') }}</p>
                        @endif
                    @endif

                    <details class="rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                        <summary class="cursor-pointer text-xs font-semibold text-brand-ink">{{ __('Using a pre-Laravel 11 app? config/broadcasting.php snippet') }}</summary>
                        <pre class="mt-2 overflow-x-auto rounded bg-brand-ink/90 p-3 text-[11px] leading-relaxed text-brand-cream"><code>'pusher' => [
    'driver' => 'pusher',
    'key' => env('PUSHER_APP_KEY'),
    'secret' => env('PUSHER_APP_SECRET'),
    'app_id' => env('PUSHER_APP_ID'),
    'options' => [
        'cluster' => env('PUSHER_APP_CLUSTER'),
        'host' => env('PUSHER_HOST'),
        'port' => env('PUSHER_PORT'),
        'scheme' => env('PUSHER_SCHEME'),
        'encrypted' => true,
        'useTLS' => true,
    ],
],</code></pre>
                        <p class="mt-2 text-[11px] text-brand-moss">{{ __('Laravel 11+ already ships these host/port/scheme options — no change needed.') }}</p>
                    </details>
                </div>
            @else
                <p class="text-sm text-brand-moss">{{ __('Records this binding so deploy preflight treats it as configured.') }}</p>
            @endif
        </div>

        <div class="flex items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="button" wire:click="saveBinding" wire:loading.attr="disabled" wire:target="saveBinding">
                <span wire:loading.remove wire:target="saveBinding">{{ $bindingModalType === 'redis' && $bindingTargets === [] ? __('Install & connect') : ($bindingModalMode === 'provision' ? __('Provision') : (in_array($bindingModalType, ['cache', 'queue', 'session', 'logging', 'mail', 'broadcasting']) ? __('Save') : __('Attach'))) }}</span>
                <span wire:loading wire:target="saveBinding" class="inline-flex items-center gap-1.5"><span class="inline-flex h-4 w-4 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>
    @endif
