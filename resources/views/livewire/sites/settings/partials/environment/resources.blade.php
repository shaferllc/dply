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
            'cache' => __('Cache'),
            'session' => __('Sessions'),
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
            fn ($b) => ! in_array($b->type, ['database', 'redis', 'queue', 'cache', 'session', 'storage'], true),
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
                            <button type="button" wire:click="openConfirmActionModal('detachBinding', @js([(string) $binding->bindingId]), @js(__('Detach binding?')), @js(__('Detach this :type binding? Its connection variables stop being injected at deploy.', ['type' => $binding->type])), @js(__('Detach')), true)" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
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
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ $bindingModalMode === 'provision' ? __('Provision new') : __('Attach existing') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ $bindingModalLabel ?: __('Binding') }}</h2>
            <button type="button" x-on:click="$dispatch('close')" class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40" aria-label="{{ __('Close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="space-y-4 px-6 py-6">
            @if ($bindingModalType === 'storage')
                {{-- One entry, two modes: attach an existing bucket or have dply
                     provision a new one. Switching re-seeds the form server-side
                     (see setBindingMode). --}}
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
                <div>
                    <x-input-label for="binding_db_target" :value="__('Database')" />
                    <select id="binding_db_target" wire:model="bindingForm.target_id" class="dply-input">
                        <option value="">{{ __('Choose a database…') }}</option>
                        @foreach ($bindingTargets as $target)
                            <option value="{{ $target['id'] }}">{{ $target['label'] }}</option>
                        @endforeach
                    </select>
                    @if ($bindingTargets === [])
                        <p class="mt-2 text-xs text-brand-moss">{{ __('No reachable databases yet. Create one on this server, or add a server to this private network.') }}</p>
                        <button type="button" wire:click="openBindingModal('database', 'provision')" class="mt-2 inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90">
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('Provision new database') }}
                        </button>
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
                <p class="text-xs text-brand-moss">{{ __('Injects the SESSION_* keys you set; blank fields fall back to the framework default. The redis driver needs the Redis resource connected too. Changing the driver, encryption or cookie path/domain signs out active sessions on the next deploy.') }}</p>
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
                <p class="text-xs text-brand-moss">{{ __('Injects FILESYSTEM_DISK=s3 and the AWS_* connection variables at deploy.') }}</p>
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
                    <select id="binding_redis_target" wire:model="bindingForm.target_id" class="dply-input">
                        <option value="">{{ __('Choose a Redis service…') }}</option>
                        @foreach ($bindingTargets as $target)
                            <option value="{{ $target['id'] }}">{{ $target['label'] }}</option>
                        @endforeach
                    </select>
                    @if ($bindingTargets === [])
                        <p class="mt-2 text-xs text-brand-moss">{{ __('No Redis-compatible service is reachable on this server or its private network peers.') }}</p>
                        @if ($existingCacheService === null)
                            {{-- Nothing installed — offer to install. --}}
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" wire:click="installCacheOnServer('redis')" wire:loading.attr="disabled" wire:target="installCacheOnServer" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-60">
                                    <x-heroicon-o-bolt class="h-3.5 w-3.5" />
                                    {{ __('Install Redis') }}
                                </button>
                                @if ($valkeyAvailable)
                                    <button type="button" wire:click="installCacheOnServer('valkey')" wire:loading.attr="disabled" wire:target="installCacheOnServer" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                                        <x-heroicon-o-bolt class="h-3.5 w-3.5" />
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
                                            <x-heroicon-o-arrows-right-left class="h-3.5 w-3.5" />
                                            {{ __('Switch to :engine', ['engine' => ucfirst($altEngine)]) }}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Lists Redis-family services on this server and on peers in the same private network. Injects REDIS_HOST / REDIS_PORT / REDIS_CLIENT (plus password and prefix when set) at deploy — peers connect over the private IP.') }}</p>
                        @if ($existingCacheService !== null && in_array($existingCacheService->engine, ['redis', 'valkey'], true))
                            <div class="mt-3 border-t border-brand-ink/10 pt-3">
                                <p class="text-[11px] text-brand-mist">{{ __('Want a different engine?') }}</p>
                                <div class="mt-1.5 flex flex-wrap gap-2">
                                    @foreach (['redis', 'valkey'] as $altEngine)
                                        @if ($altEngine !== $existingCacheService->engine && ($altEngine === 'redis' || $valkeyAvailable))
                                            <button type="button" wire:click="switchCacheOnServer('{{ $altEngine }}')" wire:loading.attr="disabled" wire:target="switchCacheOnServer" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60">
                                                <x-heroicon-o-arrows-right-left class="h-3.5 w-3.5" />
                                                {{ __('Switch to :engine', ['engine' => ucfirst($altEngine)]) }}
                                            </button>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endif
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
                <span wire:loading wire:target="saveBinding" class="inline-flex items-center gap-1.5"><span class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>
    @endif
