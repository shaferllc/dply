<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Serverless'), 'href' => route('serverless.index'), 'icon' => 'bolt'],
        ['label' => __('Glue'), 'icon' => 'link'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Serverless glue')"
        :description="__('Function sequences that connect Edge deploy hooks, Cloud redeploy endpoints, and BYO cron callbacks — orchestration across engines without leaving dply.')"
        icon="heroicon-o-link"
    >
        <x-slot:actions>
            <x-docs-link size="md" slug="edge-deploy-triggers" :label="__('Deploy triggers')" />
            <x-outline-link href="{{ route('serverless.index') }}" wire:navigate>
                <x-heroicon-o-bolt class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('All apps') }}
            </x-outline-link>
        </x-slot:actions>

        <x-slot:tabs>
            <x-server-workspace-tablist :aria-label="__('Glue sections')">
                <x-server-workspace-tab :active="$tab === 'recipes'" icon="heroicon-o-sparkles" wire:click="setTab('recipes')">{{ __('Recipes') }}</x-server-workspace-tab>
                <x-server-workspace-tab :active="$tab === 'sequences'" icon="heroicon-o-queue-list" wire:click="setTab('sequences')">{{ __('Sequences') }}</x-server-workspace-tab>
            </x-server-workspace-tablist>
        </x-slot:tabs>

        @if ($tab === 'recipes')
            @if ($recipe === null)
                <section aria-labelledby="glue-recipes-heading">
                    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                        <h2 id="glue-recipes-heading" class="text-sm font-semibold text-brand-ink">{{ __('Choose a glue pattern') }}</h2>
                        <p class="mt-0.5 text-sm text-brand-moss">{{ __('Each recipe adapts to your org — Edge hooks, serverless app actions, Cloud apps, and BYO crons.') }}</p>
                    </div>
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($catalog as $item)
                            <li>
                                <button
                                    type="button"
                                    wire:click="selectRecipe('{{ $item['key'] }}')"
                                    class="flex w-full items-start gap-4 px-5 py-4 text-left transition-colors hover:bg-brand-sand/15 sm:px-6"
                                >
                                    <span @class([
                                        'mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1',
                                        'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => $item['available'],
                                        'bg-brand-sand/45 text-brand-mist ring-brand-ink/10' => ! $item['available'],
                                    ])>
                                        <x-heroicon-o-sparkles class="h-4 w-4" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-sm font-semibold text-brand-ink">{{ $item['title'] }}</h3>
                                            @if ($item['available'])
                                                <span class="inline-flex items-center rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('Ready') }}</span>
                                            @else
                                                <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Needs setup') }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $item['summary'] }}</p>
                                        @if (! $item['available'] && $item['unavailable_reason'])
                                            <p class="mt-2 text-xs text-brand-mist">{{ $item['unavailable_reason'] }}</p>
                                        @endif
                                    </div>
                                    <x-heroicon-m-chevron-right class="mt-1 h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @else
                <section aria-labelledby="glue-recipe-detail-heading">
                    <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                        <button type="button" wire:click="backToCatalog" class="inline-flex items-center gap-1 text-sm font-semibold text-brand-sage hover:text-brand-ink">
                            <x-heroicon-m-arrow-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('All recipes') }}
                        </button>
                        <div class="mt-3 flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 id="glue-recipe-detail-heading" class="text-base font-semibold text-brand-ink">{{ $recipe['title'] }}</h2>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $recipe['summary'] }}</p>
                            </div>
                            @if ($recipe['doc_slug'])
                                <x-docs-link :slug="$recipe['doc_slug']">{{ __('Related docs') }}</x-docs-link>
                            @endif
                        </div>
                    </div>

                    @if (! $recipe['available'])
                        <div class="border-b border-brand-ink/10 bg-amber-50/60 px-5 py-4 sm:px-6 dark:bg-amber-950/20">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Inventory gap') }}</p>
                            <p class="mt-1 text-sm text-brand-ink">{{ $recipe['unavailable_reason'] }}</p>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Steps below are still useful as a template while you wire up prerequisites.') }}</p>
                        </div>
                    @endif

                    @if (count($recipe['gaps']) > 0)
                        <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Before wiring') }}</p>
                            <ul class="mt-2 space-y-1.5 text-sm text-brand-moss">
                                @foreach ($recipe['gaps'] as $gap)
                                    <li class="flex gap-2">
                                        <x-heroicon-m-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                                        <span>{{ $gap }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (count($recipe['resources']) > 0)
                        <div class="border-b border-brand-ink/10">
                            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3 sm:px-6">
                                <h3 class="text-sm font-semibold text-brand-ink">{{ __('Your inventory') }}</h3>
                            </div>
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($recipe['resources'] as $resource)
                                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5 sm:px-6">
                                        <div class="min-w-0">
                                            <span class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ $resource['kind'] }}</span>
                                            <p class="text-sm font-semibold text-brand-ink">{{ $resource['label'] }}</p>
                                            @if ($resource['meta'])
                                                <p class="mt-0.5 font-mono text-xs text-brand-moss">{{ $resource['meta'] }}</p>
                                            @endif
                                        </div>
                                        @if ($resource['href'])
                                            <a href="{{ $resource['href'] }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:text-brand-ink">
                                                {{ __('Open') }}
                                                <x-heroicon-m-arrow-up-right class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            </a>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="border-b border-brand-ink/10">
                        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3 sm:px-6">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Playbook steps') }}</h3>
                        </div>
                        <ol class="divide-y divide-brand-ink/10">
                            @foreach ($recipe['steps'] as $index => $step)
                                <li class="flex gap-3 px-5 py-4 sm:px-6">
                                    <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-xs font-bold tabular-nums text-brand-forest ring-1 ring-brand-sage/25" aria-hidden="true">
                                        {{ $index + 1 }}
                                    </span>
                                    <div class="min-w-0">
                                        <p class="text-sm text-brand-ink">{{ $step['text'] }}</p>
                                        @if ($step['href'] && $step['link_label'])
                                            <a href="{{ $step['href'] }}" wire:navigate class="mt-2 inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:text-brand-ink">
                                                {{ $step['link_label'] }}
                                                <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            </a>
                                        @endif
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    <div class="bg-brand-sand/25 px-5 py-4 sm:px-6">
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Build the sequence') }}</p>
                                <p class="mt-0.5 text-sm text-brand-moss">{{ __('When your inventory is ready, define and deploy a function sequence on your functions host.') }}</p>
                            </div>
                            <button
                                type="button"
                                wire:click="setTab('sequences')"
                                class="inline-flex shrink-0 items-center gap-1.5 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                {{ __('Open sequence builder') }}
                                <x-heroicon-m-arrow-right class="h-4 w-4 shrink-0" aria-hidden="true" />
                            </button>
                        </div>
                    </div>
                </section>
            @endif
        @else
            <section aria-labelledby="glue-sequences-heading">
                <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-4 sm:px-6">
                    <h2 id="glue-sequences-heading" class="text-sm font-semibold text-brand-ink">{{ __('Define a sequence') }}</h2>
                    <p class="mt-0.5 text-sm text-brand-moss">{{ __('Chain code actions in one functions namespace. Components may come from any package-site on the same functions host.') }}</p>
                </div>

                @if (count($snapshot['functions_hosts']) === 0)
                    <div class="flex flex-col items-start gap-4 px-5 py-10 sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('No serverless host yet') }}</p>
                                <p class="mt-1 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Add a functions host first — create a serverless app from the Serverless index.') }}
                                </p>
                            </div>
                        </div>
                        <a
                            href="{{ route('serverless.create') }}"
                            wire:navigate
                            class="inline-flex shrink-0 items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Create an app') }}
                        </a>
                    </div>
                @else
                    <form wire:submit.prevent="saveSequence" class="border-b border-brand-ink/10">
                        <div class="space-y-5 px-5 py-5 sm:px-6">
                            <div>
                                <label for="sequence-server" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Functions host') }}</label>
                                <select id="sequence-server" wire:model.live="sequenceServerId" class="mt-1.5 w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm">
                                    <option value="">{{ __('Select namespace…') }}</option>
                                    @foreach ($snapshot['functions_hosts'] as $host)
                                        <option value="{{ $host['id'] }}">{{ $host['name'] }} ({{ trans_choice(':count action|:count actions', $host['code_action_count'], ['count' => $host['code_action_count']]) }})</option>
                                    @endforeach
                                </select>
                            </div>

                            @if ($sequenceServerId !== '')
                                <div>
                                    <label for="sequence-site" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Package site') }}</label>
                                    <select id="sequence-site" wire:model="sequenceSiteId" class="mt-1.5 w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm">
                                        <option value="">{{ __('Where the sequence action lives…') }}</option>
                                        @foreach ($sequenceSites as $site)
                                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('sequenceSiteId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>

                                <div>
                                    <label for="sequence-name" class="block text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Sequence name') }}</label>
                                    <input id="sequence-name" type="text" wire:model="sequenceName" placeholder="edge-cloud-glue" class="mt-1.5 w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm" />
                                    @error('sequenceName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>

                                <div class="space-y-2">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Ordered components') }}</p>
                                    @foreach ($sequenceComponentIds as $index => $componentId)
                                        <div class="flex items-center gap-2" wire:key="sequence-component-{{ $index }}">
                                            <span class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-sand/45 text-xs font-bold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $index + 1 }}</span>
                                            <select wire:model="sequenceComponentIds.{{ $index }}" class="min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm">
                                                <option value="">{{ __('Pick a code action…') }}</option>
                                                @foreach ($codeActionsForServer as $action)
                                                    <option value="{{ $action['id'] }}">{{ $action['name'] }} · {{ $action['site_name'] }}</option>
                                                @endforeach
                                            </select>
                                            @if (count($sequenceComponentIds) > 2)
                                                <button type="button" wire:click="removeSequenceStep({{ $index }})" class="shrink-0 rounded-lg px-2 py-1.5 text-xs font-semibold text-brand-moss hover:bg-red-50 hover:text-red-700">{{ __('Remove') }}</button>
                                            @endif
                                        </div>
                                    @endforeach
                                    @error('sequenceComponentIds')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                                    <button type="button" wire:click="addSequenceStep" class="inline-flex items-center gap-1 text-xs font-semibold text-brand-forest hover:text-brand-ink">
                                        <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Add step') }}
                                    </button>
                                </div>
                            @endif
                        </div>

                        @if ($sequenceServerId !== '')
                            <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-4 sm:px-6">
                                <button type="submit" wire:loading.attr="disabled" wire:target="saveSequence" class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:opacity-60">
                                    {{ __('Save sequence') }}
                                </button>
                            </div>
                        @endif
                    </form>
                @endif

                @if (count($snapshot['sequences']) > 0)
                    <div class="border-b border-brand-ink/10">
                        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3 sm:px-6">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Existing sequences') }}</h3>
                        </div>
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($snapshot['sequences'] as $sequence)
                                <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3.5 sm:px-6">
                                    <div class="min-w-0">
                                        <p class="font-mono text-sm font-semibold text-brand-ink">{{ $sequence['name'] }}</p>
                                        <p class="mt-0.5 text-xs text-brand-moss">{{ $sequence['site_name'] }} · {{ trans_choice(':count step|:count steps', $sequence['component_count'], ['count' => $sequence['component_count']]) }}</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        @if ($sequence['href'])
                                            <a href="{{ $sequence['href'] }}" wire:navigate class="inline-flex items-center rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">{{ __('Platform') }}</a>
                                        @endif
                                        <button type="button" wire:click="deploySequence('{{ $sequence['id'] }}')" wire:loading.attr="disabled" wire:target="deploySequence('{{ $sequence['id'] }}')" class="inline-flex items-center rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream transition hover:bg-brand-forest disabled:opacity-60">
                                            {{ __('Deploy') }}
                                        </button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (count($snapshot['edge_hooks']) > 0 || count($snapshot['cloud_sites']) > 0 || count($snapshot['byo_crons']) > 0)
                    <div>
                        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-3 sm:px-6">
                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Glue endpoints') }}</h3>
                            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Inventory hooks and callbacks this org can wire into a sequence.') }}</p>
                        </div>
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($snapshot['edge_hooks'] as $hook)
                                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-5 py-3 text-sm sm:px-6">
                                    <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Edge') }}</span>
                                    <span class="font-semibold text-brand-ink">{{ $hook['site_name'] }}</span>
                                    <span class="text-brand-moss">/ {{ $hook['hook_name'] }}</span>
                                    <span class="font-mono text-xs text-brand-mist">…{{ $hook['token_prefix'] }}</span>
                                </li>
                            @endforeach
                            @foreach ($snapshot['cloud_sites'] as $cloud)
                                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-5 py-3 text-sm sm:px-6">
                                    <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Cloud') }}</span>
                                    <span class="font-semibold text-brand-ink">{{ $cloud['name'] }}</span>
                                    @if ($cloud['redeploy_hook'])
                                        <span class="font-mono text-xs text-brand-mist">{{ $cloud['redeploy_hook'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                            @foreach ($snapshot['byo_crons'] as $cron)
                                <li class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 px-5 py-3 text-sm sm:px-6">
                                    <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('BYO cron') }}</span>
                                    <span class="font-semibold text-brand-ink">{{ $cron['server_name'] }}</span>
                                    <span class="font-mono text-xs text-brand-moss">{{ $cron['cron_expression'] }}</span>
                                    @if ($cronDesc = cron_describe($cron['cron_expression']))
                                        <span class="text-xs text-brand-mist">· {{ $cronDesc }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endif
    </x-profile-shell>
</div>
