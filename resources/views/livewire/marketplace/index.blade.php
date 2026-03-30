<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <nav class="mb-2 text-sm text-brand-mist" aria-label="Breadcrumb">
            <a href="{{ route('dashboard') }}" class="hover:text-brand-ink" wire:navigate>{{ __('Dashboard') }}</a>
            <span class="mx-2" aria-hidden="true">/</span>
            <span class="text-brand-ink">{{ __('Marketplace') }}</span>
        </nav>

        <div class="mb-8">
            <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Marketplace') }}</h1>
            <p class="mt-2 max-w-3xl text-sm text-brand-moss">
                {{ __('Import ready-made recipes into your organization: Nginx snippets, deploy commands, and shortcuts to guides. Inspired by control-panel marketplaces like Ploi.') }}
            </p>
        </div>

        @if (! $hasOrganization)
            <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                {{ __('Create or join an organization to import webserver templates and deploy commands.') }}
            </div>
        @endif

        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between mb-6">
            <div class="flex flex-wrap gap-2">
                @foreach ($categories as $key => $label)
                    <button
                        type="button"
                        wire:click="$set('category', '{{ $key }}')"
                        @class([
                            'rounded-full px-3 py-1.5 text-sm font-medium transition-colors',
                            'bg-brand-ink text-brand-cream' => $category === $key,
                            'bg-brand-sand/60 text-brand-moss hover:bg-brand-sand' => $category !== $key,
                        ])
                    >
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <div class="w-full sm:max-w-xs">
                <label for="marketplace-search" class="sr-only">{{ __('Search') }}</label>
                <input
                    id="marketplace-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search recipes…') }}"
                    class="w-full rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage"
                />
            </div>
        </div>

        @if ($items->isEmpty())
            <div class="rounded-xl border border-brand-mist/80 bg-brand-sand/30 px-4 py-4 text-sm text-brand-moss">
                <p class="font-medium text-brand-ink">{{ __('No recipes match your filters.') }}</p>
                @if ($category === 'all' && $search === '')
                    <p class="mt-2 leading-relaxed">
                        {{ __('If the catalog should be full, the database may need the marketplace seed. Try:') }}
                        <code class="mx-0.5 rounded bg-white/80 px-1.5 py-0.5 font-mono text-xs text-brand-ink">php artisan migrate</code>
                        {{ __('then refresh, or run') }}
                        <code class="mx-0.5 rounded bg-white/80 px-1.5 py-0.5 font-mono text-xs text-brand-ink">php artisan db:seed --class=MarketplaceItemSeeder</code>.
                    </p>
                @endif
            </div>
        @else
            <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($items as $item)
                    <li class="flex flex-col rounded-2xl border border-brand-mist/80 bg-white p-5 shadow-sm">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ $categories[$item->category] ?? $item->category }}</p>
                                <h2 class="mt-1 font-semibold text-brand-ink">{{ $item->name }}</h2>
                            </div>
                        </div>
                        @if ($item->summary)
                            <p class="mt-2 flex-1 text-sm text-brand-moss">{{ $item->summary }}</p>
                        @endif
                        <div class="mt-4 flex flex-wrap gap-2">
                            @if ($item->recipe_type === \App\Models\MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE)
                                @if ($canImportWebserver)
                                    <button
                                        type="button"
                                        wire:click="importWebserverTemplate('{{ $item->id }}')"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-ink/90"
                                    >
                                        {{ __('Import to org') }}
                                    </button>
                                @else
                                    <span class="text-xs text-brand-moss">{{ __('Org admin only') }}</span>
                                @endif
                            @elseif ($item->recipe_type === \App\Models\MarketplaceItem::RECIPE_DEPLOY_COMMAND)
                                @if ($hasOrganization && $servers->isNotEmpty())
                                    <button
                                        type="button"
                                        wire:click="openDeployImport('{{ $item->id }}')"
                                        class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-ink/90"
                                    >
                                        {{ __('Import to server') }}
                                    </button>
                                @else
                                    <span class="text-xs text-brand-moss">{{ __('Requires a server in this organization') }}</span>
                                @endif
                            @elseif ($item->recipe_type === \App\Models\MarketplaceItem::RECIPE_EXTERNAL_LINK)
                                @php
                                    $url = $item->payload['url'] ?? '/';
                                    $href = str_starts_with($url, 'http') ? $url : url($url);
                                    $newTab = (bool) ($item->payload['open_new_tab'] ?? false);
                                @endphp
                                <a
                                    href="{{ $href }}"
                                    @if ($newTab) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
                                    class="inline-flex items-center rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                                >
                                    {{ __('Open') }}
                                </a>
                                @if (! empty($item->payload['hint']))
                                    <p class="w-full text-xs text-brand-moss">{{ $item->payload['hint'] }}</p>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($deployModalItemId)
        <div class="fixed inset-0 z-40 flex items-end justify-center sm:items-center p-4" role="dialog" aria-modal="true">
            <button type="button" class="absolute inset-0 bg-brand-ink/40" wire:click="closeDeployModal" aria-label="{{ __('Close') }}"></button>
            <div class="relative z-10 w-full max-w-md rounded-2xl border border-brand-mist bg-brand-cream p-6 shadow-xl">
                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Import deploy command') }}</h3>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Choose which server should receive this deploy script. You can edit it later on the server page.') }}</p>
                <div class="mt-4">
                    <label for="deploy-server" class="block text-sm font-medium text-brand-ink">{{ __('Server') }}</label>
                    <select
                        id="deploy-server"
                        wire:model="deployServerId"
                        class="mt-2 block w-full rounded-lg border border-brand-mist bg-white px-3 py-2 text-sm text-brand-ink"
                    >
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="closeDeployModal" class="rounded-lg px-4 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="confirmDeployImport"
                        class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-ink/90"
                    >
                        {{ __('Import') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
