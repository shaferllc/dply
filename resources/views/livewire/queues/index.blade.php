@php
    // No provisioning state: a namespace is a row, so creation is synchronous
    // and it is active or nothing.
    $statusTone = [
        \App\Modules\Queue\Models\QueueNamespace::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        \App\Modules\Queue\Models\QueueNamespace::STATUS_PAUSED => 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10',
        \App\Modules\Queue\Models\QueueNamespace::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
    ];
    $money = fn (int $cents): string => '$'.number_format($cents / 100, 2);
@endphp

<div class="contents">
    <x-workspace-nav surface="local" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8 sm:py-8">
        <x-breadcrumb-trail :items="$breadcrumbs" />

        <x-profile-shell
            dense
            :title="__('Queues')"
            :description="__('Managed job queues for your apps — an SQS-compatible endpoint your Laravel worker drains, with no Redis to run.')"
            icon="heroicon-o-queue-list"
        >
            <x-slot:actions>
                @if ($canManage && $namespaces->isNotEmpty() && ! $atLimit)
                    <button
                        type="button"
                        wire:click="startCreate"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('New queue') }}
                    </button>
                @endif
            </x-slot:actions>

            @if ($namespaces->isNotEmpty())
                <x-slot:stats>
                    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3" aria-label="{{ __('Queues at a glance') }}">
                        <x-fleet-stat :label="__('On this bill')">
                            <p class="mt-2 flex items-baseline gap-1">
                                <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $money($monthlyCents) }}</span>
                                <span class="text-xs text-brand-moss">/{{ __('mo') }}</span>
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">
                                @if (! $billingEnabled)
                                    {{ __('Free while in beta') }}
                                @else
                                    {{ trans_choice(':count billable queue|:count billable queues', $billableCount, ['count' => $billableCount]) }}
                                @endif
                            </p>
                        </x-fleet-stat>
                        <x-fleet-stat :label="__('Included free')">
                            <p class="mt-2 flex items-baseline gap-1.5">
                                <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $freeCount }}</span>
                                <span class="text-xs text-brand-moss">{{ trans_choice('queue|queues', $freeCount) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Serving Serverless sites') }}</p>
                        </x-fleet-stat>
                        <x-fleet-stat :label="__('Queues')" class="col-span-2 sm:col-span-1">
                            <p class="mt-2 flex items-baseline gap-1.5">
                                <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $namespaces->count() }}</span>
                                @if ($entitlement->hasNamespaceLimit())
                                    <span class="text-xs text-brand-moss">{{ __('of :max', ['max' => $entitlement->maxNamespaces]) }}</span>
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('On the :plan plan', ['plan' => ucfirst($entitlement->planKey)]) }}</p>
                        </x-fleet-stat>
                    </dl>
                </x-slot:stats>
            @endif

            <div class="px-3 py-3 sm:px-4">
                @if ($endpointBase === '')
                    {{-- Without a publicly reachable URL there is no endpoint to hand
                         out, and a queue nobody can reach is worse than none. --}}
                    <x-alert tone="warning" class="mb-3">
                        {{ __('dply Queue has no public endpoint configured, so queues created here would be unreachable. Set DPLY_QUEUE_PUBLIC_URL (or DPLY_PUBLIC_APP_URL) first.') }}
                    </x-alert>
                @endif

                @if ($namespaces->isEmpty())
                    <x-empty-state
                        icon="heroicon-o-queue-list"
                        :title="__('No queues yet')"
                        :description="__('A queue gives your app a managed place to put background jobs — no Redis to provision, and your worker drains it over the SQS protocol Laravel already speaks. Serverless sites get one automatically, free.')"
                    >
                        @if ($canManage && $endpointBase !== '')
                            <button
                                type="button"
                                wire:click="startCreate"
                                class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create a queue') }}
                            </button>
                        @endif
                    </x-empty-state>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">
                                    <th scope="col" class="py-2 pr-3">{{ __('Queue') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('Status') }}</th>
                                    <th scope="col" class="px-3 py-2">{{ __('Tier') }}</th>
                                    <th scope="col" class="px-3 py-2 text-right">{{ __('Depth') }}</th>
                                    <th scope="col" class="px-3 py-2 text-right">{{ __('Monthly') }}</th>
                                    <th scope="col" class="py-2 pl-3"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5">
                                @foreach ($namespaces as $namespace)
                                    @php
                                        $depth = $depths[$namespace->id] ?? null;
                                        $tierCfg = $namespace->tierConfig();
                                    @endphp
                                    <tr class="align-middle">
                                        <td class="py-2.5 pr-3">
                                            <a
                                                href="{{ route('queues.show', $namespace) }}"
                                                wire:navigate
                                                class="font-medium text-brand-ink hover:text-brand-forest"
                                            >
                                                {{ $namespace->name }}
                                            </a>
                                            @if ($namespace->site !== null)
                                                <p class="mt-0.5 text-xs text-brand-mist">{{ $namespace->site->name }}</p>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusTone[$namespace->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                                                {{ ucfirst($namespace->status) }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-2.5 text-brand-moss">{{ $tierCfg->label }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums text-brand-ink">
                                            @if ($depth === null)
                                                {{-- The job store is a separate database; say so rather
                                                     than render a zero that looks like an empty queue. --}}
                                                <span class="text-brand-mist" title="{{ __('The job store could not be reached.') }}">{{ __('—') }}</span>
                                            @else
                                                {{ number_format($depth->total()) }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums">
                                            @if (! $namespace->isBillable())
                                                <span class="inline-flex items-center gap-1 text-xs font-medium text-brand-forest">
                                                    <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                    {{ __('Included') }}
                                                </span>
                                            @elseif (! $billingEnabled)
                                                <span class="text-xs text-brand-mist">{{ __('Free (beta)') }}</span>
                                            @else
                                                <span class="text-brand-ink">{{ $money($tierCfg->priceCents) }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 pl-3 text-right">
                                            <a
                                                href="{{ route('queues.show', $namespace) }}"
                                                wire:navigate
                                                class="text-xs font-semibold text-brand-moss hover:text-brand-ink"
                                            >
                                                {{ __('Manage') }}
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($atLimit)
                        <p class="mt-3 text-xs text-brand-mist">
                            {{ __('This plan allows :max queue(s). Upgrade to add another.', ['max' => $entitlement->maxNamespaces]) }}
                        </p>
                    @endif
                @endif
            </div>
        </x-profile-shell>
    </div>

    {{-- Create modal --}}
    <x-modal name="queue-create-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-semibold text-brand-ink">{{ __('New queue') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Creates a managed queue endpoint and its first credential. Point your app at it with QUEUE_CONNECTION=dply.') }}
            </p>

            <div class="mt-4 space-y-4">
                <div>
                    <x-input-label for="queue_name" :value="__('Name')" />
                    <input
                        id="queue_name"
                        type="text"
                        wire:model="createName"
                        class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        placeholder="{{ __('checkout-workers') }}"
                    />
                    <x-input-error :messages="$errors->get('createName')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="queue_tier" :value="__('Capacity tier')" />
                    <select
                        id="queue_tier"
                        wire:model.live="createTier"
                        class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    >
                        @foreach ($tiers as $slug => $tierOption)
                            <option value="{{ $slug }}">
                                {{ $tierOption->label }} — {{ number_format($tierOption->maxQueueDepth) }} {{ __('jobs deep') }}, {{ number_format($tierOption->requestsPerMinute) }} {{ __('req/min') }} — {{ $money($tierOption->priceCents) }}/{{ __('mo') }}
                            </option>
                        @endforeach
                    </select>
                </div>

                @php $chosen = $tiers[$createTier] ?? null; @endphp
                <label class="flex items-start gap-2 rounded-lg bg-brand-sand/30 p-3">
                    <input type="checkbox" wire:model="confirmCreateCharge" class="mt-0.5 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage" />
                    <span class="text-xs text-brand-moss">
                        @if $billingEnabled
                            {{ __('I understand this adds :price/month to my workspace subscription.', ['price' => $chosen ? $money($chosen->priceCents) : '—']) }}
                        @else
                            {{ __('I understand this queue is free during the beta and will bill at :price/month when dply Queue leaves beta.', ['price' => $chosen ? $money($chosen->priceCents) : '—']) }}
                        @endif
                        {{ __('Queues attached to a dply Serverless site are always included at no charge.') }}
                    </span>
                </label>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancelCreate" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                    {{ __('Cancel') }}
                </button>
                <button
                    type="button"
                    wire:click="createNamespace"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3.5 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest"
                >
                    {{ __('Create queue') }}
                </button>
            </div>
        </div>
    </x-modal>

    {{-- Delete modal --}}
    <x-modal name="queue-delete-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        <div class="p-5 sm:p-6">
            <h2 class="text-base font-semibold text-brand-ink">
                {{ __('Delete :name?', ['name' => $deletingNamespace?->name ?? __('this queue')]) }}
            </h2>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Any jobs still in this queue are discarded, and apps using its credentials will start failing to enqueue. This cannot be undone.') }}
            </p>

            @php $pending = $deletingNamespace !== null ? ($depths[$deletingNamespace->id] ?? null) : null; @endphp
            @if ($pending !== null && $pending->total() > 0)
                <x-alert tone="warning" class="mt-3">
                    {{ trans_choice(
                        ':count job is still queued and will be discarded.|:count jobs are still queued and will be discarded.',
                        $pending->total(),
                        ['count' => number_format($pending->total())],
                    ) }}
                </x-alert>
            @endif

            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="cancelDelete" class="rounded-lg px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">
                    {{ __('Cancel') }}
                </button>
                <x-danger-button wire:click="deleteNamespace">{{ __('Delete queue') }}</x-danger-button>
            </div>
        </div>
    </x-modal>
</div>
