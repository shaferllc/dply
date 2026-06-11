<div>
    <div class="border-b border-brand-ink/10 bg-white">
        <div class="dply-page-shell py-8">
            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Infrastructure'), 'href' => route('infrastructure.index'), 'icon' => 'rectangle-group'],
                ['label' => __('Serverless'), 'href' => route('serverless.index'), 'icon' => 'bolt'],
                ['label' => __('Glue'), 'icon' => 'link'],
            ]" />

            <x-page-header
                :title="__('Serverless glue')"
                :description="__('OpenWhisk sequences connecting Edge deploy hooks, Cloud redeploy endpoints, and BYO cron callbacks — orchestration across engines without leaving dply.')"
                doc-route="docs.markdown"
                doc-slug="edge-deploy-triggers"
                flush
            >
                <x-slot name="actions">
                    <a href="{{ route('serverless.index') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('All functions') }}</a>
                </x-slot>
            </x-page-header>

            <div class="mt-6 flex gap-1 rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-1">
                <button type="button" wire:click="setTab('recipes')"
                    @class([
                        'flex-1 rounded-lg px-4 py-2 text-sm font-semibold transition',
                        'bg-white text-brand-ink shadow-sm' => $tab === 'recipes',
                        'text-brand-moss hover:text-brand-ink' => $tab !== 'recipes',
                    ])>{{ __('Recipes') }}</button>
                <button type="button" wire:click="setTab('sequences')"
                    @class([
                        'flex-1 rounded-lg px-4 py-2 text-sm font-semibold transition',
                        'bg-white text-brand-ink shadow-sm' => $tab === 'sequences',
                        'text-brand-moss hover:text-brand-ink' => $tab !== 'sequences',
                    ])>{{ __('Sequences') }}</button>
            </div>
        </div>
    </div>

    <div class="min-h-[50vh] bg-brand-cream py-10">
        <div class="dply-page-shell max-w-3xl">
            @if ($tab === 'recipes')
                @if ($recipe === null)
                    <section class="space-y-4">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Choose a glue pattern') }}</h2>
                        <p class="text-sm text-brand-moss">{{ __('Each recipe adapts to your org — Edge hooks, functions namespace actions, Cloud apps, and BYO crons.') }}</p>

                        <ul class="mt-4 space-y-3">
                            @foreach ($catalog as $item)
                                <li>
                                    <button
                                        type="button"
                                        wire:click="selectRecipe('{{ $item['key'] }}')"
                                        @class([
                                            'w-full rounded-2xl border p-5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md',
                                            'border-brand-sage/40 bg-white ring-1 ring-brand-sage/20' => $item['available'],
                                            'border-brand-ink/10 bg-brand-sand/20 opacity-90' => ! $item['available'],
                                        ])
                                    >
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <h3 class="text-base font-semibold text-brand-ink">{{ $item['title'] }}</h3>
                                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $item['summary'] }}</p>
                                            </div>
                                            @if ($item['available'])
                                                <span class="rounded-full bg-brand-sage/15 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest ring-1 ring-brand-sage/25">{{ __('Ready') }}</span>
                                            @else
                                                <span class="rounded-full bg-brand-ink/5 px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ __('Needs setup') }}</span>
                                            @endif
                                        </div>
                                        @if (! $item['available'] && $item['unavailable_reason'])
                                            <p class="mt-3 text-xs text-brand-moss">{{ $item['unavailable_reason'] }}</p>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @else
                    <section class="space-y-6">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <button type="button" wire:click="backToCatalog" class="text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('← All recipes') }}</button>
                                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ $recipe['title'] }}</h2>
                                <p class="mt-1 text-sm text-brand-moss">{{ $recipe['summary'] }}</p>
                            </div>
                            @if ($recipe['doc_slug'])
                                <x-docs-link :slug="$recipe['doc_slug']">{{ __('Related docs') }}</x-docs-link>
                            @endif
                        </div>

                        @if (! $recipe['available'])
                            <div class="rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                                <p class="font-medium">{{ __('Inventory gap') }}</p>
                                <p class="mt-1">{{ $recipe['unavailable_reason'] }}</p>
                                <p class="mt-2 text-xs">{{ __('Steps below are still useful as a template while you wire up prerequisites.') }}</p>
                            </div>
                        @endif

                        @if (count($recipe['gaps']) > 0)
                            <div class="rounded-xl border border-amber-200/80 bg-amber-50/50 px-4 py-3">
                                <p class="text-xs font-semibold uppercase tracking-[0.16em] text-amber-900">{{ __('Before wiring') }}</p>
                                <ul class="mt-2 space-y-1 text-sm text-amber-950">
                                    @foreach ($recipe['gaps'] as $gap)
                                        <li class="flex gap-2"><span aria-hidden="true">•</span><span>{{ $gap }}</span></li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (count($recipe['resources']) > 0)
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Your inventory') }}</h3>
                                <ul class="mt-3 space-y-2">
                                    @foreach ($recipe['resources'] as $resource)
                                        <li class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 text-sm">
                                            <div>
                                                <span class="text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $resource['kind'] }}</span>
                                                <p class="font-semibold text-brand-ink">{{ $resource['label'] }}</p>
                                                @if ($resource['meta'])
                                                    <p class="mt-0.5 font-mono text-xs text-brand-moss">{{ $resource['meta'] }}</p>
                                                @endif
                                            </div>
                                            @if ($resource['href'])
                                                <a href="{{ $resource['href'] }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Open') }}</a>
                                            @endif
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Playbook steps') }}</h3>
                            <ol class="mt-3 space-y-3">
                                @foreach ($recipe['steps'] as $index => $step)
                                    <li class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 text-sm">
                                        <span class="text-xs font-semibold text-brand-moss">{{ $index + 1 }}.</span>
                                        <span class="text-brand-ink">{{ $step['text'] }}</span>
                                        @if ($step['href'] && $step['link_label'])
                                            <a href="{{ $step['href'] }}" wire:navigate class="mt-2 inline-block text-xs font-semibold text-brand-forest hover:underline">{{ $step['link_label'] }} →</a>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </div>

                        <div class="rounded-xl border border-brand-sage/30 bg-brand-sage/5 px-4 py-3 text-sm text-brand-ink">
                            <p class="font-medium">{{ __('Build the sequence') }}</p>
                            <p class="mt-1 text-brand-moss">{{ __('When your inventory is ready, switch to the Sequences tab to define and deploy an OpenWhisk sequence on your functions host.') }}</p>
                            <button type="button" wire:click="setTab('sequences')" class="mt-3 text-sm font-semibold text-brand-forest hover:underline">{{ __('Open sequence builder') }} →</button>
                        </div>
                    </section>
                @endif
            @else
                <section class="space-y-8">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Define a sequence') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Chain code actions in one OpenWhisk namespace. Components may come from any package-site on the same functions host.') }}</p>
                    </div>

                    @if (count($snapshot['functions_hosts']) === 0)
                        <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-6 text-sm text-brand-moss">
                            {{ __('Add a DigitalOcean Functions host first — create a serverless function from the Serverless index.') }}
                            <a href="{{ route('serverless.create') }}" wire:navigate class="mt-2 inline-block font-semibold text-brand-forest hover:underline">{{ __('Create a function') }} →</a>
                        </div>
                    @else
                        <form wire:submit.prevent="saveSequence" class="dply-card space-y-4 p-6">
                            <div>
                                <label for="sequence-server" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Functions host') }}</label>
                                <select id="sequence-server" wire:model.live="sequenceServerId" class="mt-1 w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink">
                                    <option value="">{{ __('Select namespace…') }}</option>
                                    @foreach ($snapshot['functions_hosts'] as $host)
                                        <option value="{{ $host['id'] }}">{{ $host['name'] }} ({{ trans_choice(':count action|:count actions', $host['code_action_count'], ['count' => $host['code_action_count']]) }})</option>
                                    @endforeach
                                </select>
                            </div>

                            @if ($sequenceServerId !== '')
                                <div>
                                    <label for="sequence-site" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Package site') }}</label>
                                    <select id="sequence-site" wire:model="sequenceSiteId" class="mt-1 w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink">
                                        <option value="">{{ __('Where the sequence action lives…') }}</option>
                                        @foreach ($sequenceSites as $site)
                                            <option value="{{ $site->id }}">{{ $site->name }}</option>
                                        @endforeach
                                    </select>
                                    @error('sequenceSiteId')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>

                                <div>
                                    <label for="sequence-name" class="block text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Sequence name') }}</label>
                                    <input id="sequence-name" type="text" wire:model="sequenceName" placeholder="edge-cloud-glue" class="mt-1 w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono text-brand-ink" />
                                    @error('sequenceName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>

                                <div class="space-y-2">
                                    <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">{{ __('Ordered components') }}</p>
                                    @foreach ($sequenceComponentIds as $index => $componentId)
                                        <div class="flex items-center gap-2">
                                            <span class="w-6 shrink-0 text-xs font-semibold text-brand-moss">{{ $index + 1 }}.</span>
                                            <select wire:model="sequenceComponentIds.{{ $index }}" class="min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink">
                                                <option value="">{{ __('Pick a code action…') }}</option>
                                                @foreach ($codeActionsForServer as $action)
                                                    <option value="{{ $action['id'] }}">{{ $action['name'] }} · {{ $action['site_name'] }}</option>
                                                @endforeach
                                            </select>
                                            @if (count($sequenceComponentIds) > 2)
                                                <button type="button" wire:click="removeSequenceStep({{ $index }})" class="shrink-0 text-xs font-semibold text-brand-moss hover:text-red-600">{{ __('Remove') }}</button>
                                            @endif
                                        </div>
                                    @endforeach
                                    @error('sequenceComponentIds')<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                                    <button type="button" wire:click="addSequenceStep" class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Add step') }}</button>
                                </div>

                                <button type="submit" wire:loading.attr="disabled" wire:target="saveSequence" class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-60">
                                    {{ __('Save sequence') }}
                                </button>
                            @endif
                        </form>
                    @endif

                    @if (count($snapshot['sequences']) > 0)
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Existing sequences') }}</h3>
                            <ul class="mt-3 divide-y divide-brand-ink/10 rounded-xl border border-brand-ink/10 bg-white">
                                @foreach ($snapshot['sequences'] as $sequence)
                                    <li class="flex flex-wrap items-center justify-between gap-3 px-4 py-3 text-sm">
                                        <div>
                                            <p class="font-semibold font-mono text-brand-ink">{{ $sequence['name'] }}</p>
                                            <p class="text-xs text-brand-moss">{{ $sequence['site_name'] }} · {{ trans_choice(':count step|:count steps', $sequence['component_count'], ['count' => $sequence['component_count']]) }}</p>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            @if ($sequence['href'])
                                                <a href="{{ $sequence['href'] }}" wire:navigate class="rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Platform') }}</a>
                                            @endif
                                            <button type="button" wire:click="deploySequence('{{ $sequence['id'] }}')" wire:loading.attr="disabled" wire:target="deploySequence('{{ $sequence['id'] }}')" class="rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-60">
                                                {{ __('Deploy') }}
                                            </button>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if (count($snapshot['edge_hooks']) > 0 || count($snapshot['cloud_sites']) > 0 || count($snapshot['byo_crons']) > 0)
                        <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-4 text-sm">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Glue endpoints') }}</p>
                            <ul class="mt-3 space-y-2 text-brand-moss">
                                @foreach ($snapshot['edge_hooks'] as $hook)
                                    <li>{{ __('Edge') }} · {{ $hook['site_name'] }} / {{ $hook['hook_name'] }} <span class="font-mono text-xs">…{{ $hook['token_prefix'] }}</span></li>
                                @endforeach
                                @foreach ($snapshot['cloud_sites'] as $cloud)
                                    <li>{{ __('Cloud') }} · {{ $cloud['name'] }} @if ($cloud['redeploy_hook'])<span class="font-mono text-xs">{{ $cloud['redeploy_hook'] }}</span>@endif</li>
                                @endforeach
                                @foreach ($snapshot['byo_crons'] as $cron)
                                    <li>{{ __('BYO cron') }} · {{ $cron['server_name'] }} <span class="font-mono text-xs">{{ $cron['cron_expression'] }}</span>@if ($cronDesc = cron_describe($cron['cron_expression'])) <span class="text-xs text-brand-mist">· {{ $cronDesc }}</span>@endif</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </div>
</div>
