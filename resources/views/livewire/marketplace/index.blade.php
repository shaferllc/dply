@php
    $catalogTotal = (int) ($catalogTotal ?? 0);
    $hasCatalogInScope = $catalogTotal > 0;
    $filtersActive = $category !== 'all' || trim($search) !== '';
@endphp

<div class="contents">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-breadcrumb-trail
            doc-route="docs.index"
            :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Marketplace'), 'icon' => 'rectangle-group'],
            ]"
        />

        <x-profile-shell
            :title="__('Marketplace')"
            :description="__('Import curated starters into the right scope: webserver templates go to your organization, deploy starters and saved commands go to a server, and runbooks go to a project operations page. Guides and integrations stay linked here for discovery.')"
            icon="heroicon-o-rectangle-group"
        >
            @if (feature('surface.scripts'))
                <x-slot:actions>
                    <a
                        href="{{ route('scripts.index') }}"
                        wire:navigate
                        class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-code-bracket class="h-4 w-4" aria-hidden="true" />
                        {{ __('Your scripts') }}
                    </a>
                </x-slot:actions>
            @endif

            @if (session('error'))
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ session('error') }}</div>
                </div>
            @endif
            @if ($hasCatalogInScope)
                <x-slot:stats>
                    <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                <x-heroicon-o-rectangle-stack class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate">{{ __('Recipes') }}</span>
                            </dt>
                            <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $catalogTotal }}</dd>
                        </div>
                        <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                            <dt class="flex items-center gap-1.5 text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                                <x-heroicon-o-funnel class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                <span class="truncate">{{ __('Showing') }}</span>
                            </dt>
                            <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $items->total() }}</dd>
                        </div>
                    </dl>
                </x-slot:stats>
            @endif

            @if (! $hasOrganization)
                <div class="border-b border-brand-ink/10 bg-amber-50/60 px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-800 ring-1 ring-amber-200/80">
                            <x-heroicon-o-shield-exclamation class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                            <h2 class="mt-0.5 text-sm font-semibold text-brand-ink">{{ __('Organization required') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Create or join an organization to import webserver templates and deploy commands.') }}
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            @unless ($hasCatalogInScope)
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6" aria-labelledby="marketplace-empty-heading">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-rectangle-group class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <h2 id="marketplace-empty-heading" class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No recipes yet') }}</h2>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Curated starters, runbooks, and integrations will appear here when they become available.') }}
                    </p>
                </div>
            @else
                <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-3 py-3 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                    <nav class="flex min-w-0 flex-wrap gap-2" aria-label="{{ __('Marketplace categories') }}">
                        @foreach ($categories as $key => $label)
                            <button
                                type="button"
                                wire:click="$set('category', '{{ $key }}')"
                                class="rounded-full border px-3 py-1.5 text-xs font-semibold transition {{ $category === $key ? 'border-brand-ink bg-brand-ink text-brand-cream' : 'border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' }}"
                            >
                                {{ $label }}
                            </button>
                        @endforeach
                    </nav>
                    <div class="flex w-full items-center gap-2 sm:max-w-xs">
                        <div class="min-w-0 flex-1">
                            <label for="marketplace-search" class="sr-only">{{ __('Search') }}</label>
                            <x-text-input
                                id="marketplace-search"
                                type="search"
                                wire:model.live.debounce.300ms="search"
                                class="mt-0 w-full"
                                placeholder="{{ __('Search recipes…') }}"
                                autocomplete="off"
                            />
                        </div>
                        @if ($filtersActive)
                            <button
                                type="button"
                                wire:click="resetFilters"
                                class="inline-flex shrink-0 items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-xs font-semibold text-brand-moss shadow-sm transition hover:bg-brand-sand/40 hover:text-brand-ink"
                            >
                                {{ __('Reset') }}
                            </button>
                        @endif
                    </div>
                </div>

                @if ($items->isEmpty())
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-semibold text-brand-ink">{{ __('No recipes match your filters') }}</p>
                        <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                            {{ __('Try another category, or clear search to browse the full catalog.') }}
                        </p>
                        <button
                            type="button"
                            wire:click="resetFilters"
                            class="mt-4 text-xs font-semibold text-brand-sage hover:text-brand-ink"
                        >
                            {{ __('Reset filters') }}
                        </button>
                    </div>
                @else
                    <ul class="grid gap-4 px-5 py-5 sm:grid-cols-2 sm:px-6 lg:grid-cols-3">
                        @foreach ($items as $item)
                            <li
                                wire:key="marketplace-item-{{ $item->id }}"
                                class="flex flex-col rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm"
                            >
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
                                    @if ($item->recipe_type === \App\Modules\Marketplace\Models\MarketplaceItem::RECIPE_WEBSERVER_TEMPLATE)
                                        @if ($canImportWebserver)
                                            <button
                                                type="button"
                                                wire:click="importWebserverTemplate('{{ $item->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="importWebserverTemplate"
                                                class="inline-flex min-w-[8.5rem] items-center justify-center gap-2 rounded-lg bg-brand-ink px-3 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-ink/90 disabled:opacity-50"
                                            >
                                                <span wire:loading.remove wire:target="importWebserverTemplate">{{ __('Import to org') }}</span>
                                                <span wire:loading wire:target="importWebserverTemplate" class="inline-flex items-center gap-2">
                                                    <x-spinner variant="cream" size="sm" />
                                                    {{ __('Importing…') }}
                                                </span>
                                            </button>
                                        @else
                                            <span class="text-xs text-brand-moss">{{ __('Org admin only') }}</span>
                                        @endif
                                    @elseif ($item->recipe_type === \App\Modules\Marketplace\Models\MarketplaceItem::RECIPE_DEPLOY_COMMAND)
                                        @if ($hasOrganization && $servers->isNotEmpty())
                                            <button
                                                type="button"
                                                wire:click="openDeployImport('{{ $item->id }}')"
                                                class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-ink/90"
                                            >
                                                {{ __('Import to deploy') }}
                                            </button>
                                        @else
                                            <span class="text-xs text-brand-moss">{{ __('Requires a server in this organization') }}</span>
                                        @endif
                                    @elseif ($item->recipe_type === \App\Modules\Marketplace\Models\MarketplaceItem::RECIPE_SERVER_RECIPE)
                                        @if ($hasOrganization && $servers->isNotEmpty())
                                            <button
                                                type="button"
                                                wire:click="openServerRecipeImport('{{ $item->id }}')"
                                                class="inline-flex items-center rounded-lg bg-brand-ink px-3 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-ink/90"
                                            >
                                                {{ __('Import to saved commands') }}
                                            </button>
                                        @else
                                            <span class="text-xs text-brand-moss">{{ __('Requires a server in this organization') }}</span>
                                        @endif
                                    @elseif ($item->recipe_type === \App\Modules\Marketplace\Models\MarketplaceItem::RECIPE_WORKSPACE_RUNBOOK)
                                        @if ($hasOrganization && $workspaces->isNotEmpty())
                                            <button
                                                type="button"
                                                wire:click="openRunbookImport('{{ $item->id }}')"
                                                class="inline-flex items-center rounded-lg bg-brand-forest px-3 py-2 text-sm font-semibold text-white hover:bg-brand-forest/90"
                                            >
                                                {{ __('Import to project') }}
                                            </button>
                                        @else
                                            <span class="text-xs text-brand-moss">{{ __('Requires a project in this organization') }}</span>
                                        @endif
                                    @elseif ($item->recipe_type === \App\Modules\Marketplace\Models\MarketplaceItem::RECIPE_SCRIPT)
                                        @if ($canCloneScripts)
                                            <button
                                                type="button"
                                                wire:click="cloneScriptPreset('{{ $item->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="cloneScriptPreset"
                                                class="inline-flex min-w-[8.5rem] items-center justify-center gap-2 rounded-lg bg-brand-ink px-3 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-ink/90 disabled:opacity-50"
                                            >
                                                <span wire:loading.remove wire:target="cloneScriptPreset">{{ __('Add to my scripts') }}</span>
                                                <span wire:loading wire:target="cloneScriptPreset" class="inline-flex items-center gap-2">
                                                    <x-spinner variant="cream" size="sm" />
                                                    {{ __('Adding…') }}
                                                </span>
                                            </button>
                                        @else
                                            <span class="text-xs text-brand-moss">{{ __('Requires an organization you can add scripts to') }}</span>
                                        @endif
                                        @if (! empty($item->payload['run_as_user']))
                                            <p class="w-full text-xs text-brand-moss">{{ __('Run as:') }} <code class="text-brand-ink">{{ $item->payload['run_as_user'] }}</code></p>
                                        @endif
                                    @elseif ($item->recipe_type === \App\Modules\Marketplace\Models\MarketplaceItem::RECIPE_EXTERNAL_LINK)
                                        @php
                                            $url = $item->payload['url'] ?? '/';
                                            $href = str_starts_with($url, 'http') ? $url : url($url);
                                            $newTab = (bool) ($item->payload['open_new_tab'] ?? false);
                                        @endphp
                                        <a
                                            href="{{ $href }}"
                                            @if ($newTab) target="_blank" rel="noopener noreferrer" @else wire:navigate @endif
                                            class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
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
                    @if ($items->hasPages())
                        <div class="border-t border-brand-ink/10 px-5 py-3 sm:px-6">
                            <x-table-pager :paginator="$items" :noun="__('recipes')" />
                        </div>
                    @endif
                @endif
            @endunless
        </x-profile-shell>
    </div>

    @if ($deployModalItemId || $serverRecipeModalItemId || $runbookModalItemId)
        <div class="fixed inset-0 z-40 flex items-end justify-center p-4 sm:items-center" role="dialog" aria-modal="true">
            <button type="button" class="absolute inset-0 bg-brand-ink/40" wire:click="closeServerImportModal" aria-label="{{ __('Close') }}"></button>
            <div class="relative z-10 w-full max-w-md rounded-2xl border border-brand-ink/10 bg-brand-cream p-6 shadow-xl">
                <h3 class="text-lg font-semibold text-brand-ink">
                    @if ($runbookModalItemId)
                        {{ __('Import runbook') }}
                    @elseif ($deployModalItemId)
                        {{ __('Import deploy command') }}
                    @else
                        {{ __('Import saved command') }}
                    @endif
                </h3>
                <p class="mt-2 text-sm text-brand-moss">
                    @if ($runbookModalItemId)
                        {{ __('Choose which project should receive this runbook. Edit it later on the project Operations tab.') }}
                    @elseif ($deployModalItemId)
                        {{ __('Choose which server should receive this deploy script. You can edit it later on the Deploy page.') }}
                    @else
                        {{ __('Choose which server should receive this saved command. You can run or edit it later on the Saved commands page.') }}
                    @endif
                </p>
                <div class="mt-4">
                    @if ($runbookModalItemId)
                        <label for="runbook-workspace" class="block text-sm font-medium text-brand-ink">{{ __('Project') }}</label>
                        <select
                            id="runbook-workspace"
                            wire:model="runbookWorkspaceId"
                            class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink"
                        >
                            @foreach ($workspaces as $workspace)
                                <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                            @endforeach
                        </select>
                    @else
                        <label for="deploy-server" class="block text-sm font-medium text-brand-ink">{{ __('Server') }}</label>
                        <select
                            id="deploy-server"
                            wire:model="deployServerId"
                            class="mt-2 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink"
                        >
                            @foreach ($servers as $server)
                                <option value="{{ $server->id }}">{{ $server->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                <div class="mt-6 flex justify-end gap-2">
                    <button type="button" wire:click="closeServerImportModal" class="rounded-lg px-4 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="{{ $runbookModalItemId ? 'confirmRunbookImport' : ($deployModalItemId ? 'confirmDeployImport' : 'confirmServerRecipeImport') }}"
                        class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-ink/90"
                    >
                        {{ __('Import') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
