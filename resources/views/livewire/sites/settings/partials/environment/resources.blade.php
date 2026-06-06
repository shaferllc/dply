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
            fn ($b) => ! in_array($b->type, ['database', 'redis', 'queue', 'cache', 'storage'], true),
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
            @elseif ($bindingModalType === 'cache')
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
                    <div>
                        <x-input-label for="binding_storage_key" :value="__('Access key ID')" />
                        <x-text-input id="binding_storage_key" wire:model="bindingForm.access_key_id" class="mt-1 block w-full font-mono text-sm" />
                    </div>
                    <div>
                        <x-input-label for="binding_storage_secret" :value="__('Secret access key')" />
                        <x-text-input id="binding_storage_secret" type="password" wire:model="bindingForm.secret_access_key" class="mt-1 block w-full font-mono text-sm" />
                    </div>
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
                <span wire:loading wire:target="saveBinding" class="inline-flex items-center gap-1.5"><span class="inline-flex h-3.5 w-3.5 items-center justify-center"><x-spinner size="sm" /></span>{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>
    @endif
