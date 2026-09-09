@php
    $statusTone = [
        \App\Modules\Queue\Models\QueueNamespace::STATUS_ACTIVE => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        \App\Modules\Queue\Models\QueueNamespace::STATUS_PAUSED => 'bg-amber-100 text-amber-700 ring-amber-200',
        \App\Modules\Queue\Models\QueueNamespace::STATUS_FAILED => 'bg-red-100 text-red-700 ring-red-200',
    ];
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell
            dense
            :organization="$organization"
            section="queues"
            :title="__('Queues')"
            :description="__('dply Queue is a managed job queue your apps push to over the SQS wire protocol — no package to install, and nothing to provision. Laravel’s built-in driver talks to it unchanged.')"
            icon="heroicon-o-queue-list"
            :breadcrumb="$breadcrumbs"
        >
            <x-slot:actions>
                @if ($featureActive && $canManage && $namespaces->isNotEmpty())
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
                        <x-infrastructure-stat :label="__('Jobs this month')">
                            <p class="mt-2 flex items-baseline gap-1.5">
                                <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ number_format($usedJobs) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">
                                @if ($entitlement->monthlyIncludedJobs > 0)
                                    {{ __('of :included included', ['included' => number_format($entitlement->monthlyIncludedJobs)]) }}
                                @else
                                    {{ __('Unlimited on this plan') }}
                                @endif
                            </p>
                        </x-infrastructure-stat>
                        <x-infrastructure-stat :label="__('Queues')">
                            <p class="mt-2 flex items-baseline gap-1.5">
                                <span class="text-2xl font-semibold tabular-nums text-brand-ink">{{ $namespaces->count() }}</span>
                                <span class="text-xs text-brand-moss">{{ trans_choice('queue|queues', $namespaces->count()) }}</span>
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">
                                {{ $entitlement->maxNamespaces > 0
                                    ? __('Limit :max on this plan', ['max' => number_format($entitlement->maxNamespaces)])
                                    : __('No limit on this plan') }}
                            </p>
                        </x-infrastructure-stat>
                        <x-infrastructure-stat
                            :label="__('Managed queue')"
                            @class([
                                'border-brand-sage/30 bg-brand-sage/8' => $featureActive,
                                'col-span-2 sm:col-span-1' => true,
                            ])
                        >
                            <p class="mt-2 flex items-center gap-1.5">
                                @if ($featureActive)
                                    <x-heroicon-m-check-circle class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                                    <span class="text-sm font-semibold text-brand-forest">{{ __('Enabled') }}</span>
                                @else
                                    <x-heroicon-m-no-symbol class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                                    <span class="text-sm font-semibold text-brand-mist">{{ __('Unavailable') }}</span>
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Feature flag') }}</p>
                        </x-infrastructure-stat>
                    </dl>
                </x-slot:stats>
            @endif

            {{-- Said plainly rather than as a bill surprise: metering runs before
                 pricing does, so the allowance is real even while billing is dark. --}}
            @if ($overIncluded)
                <div class="border-b border-brand-ink/10 bg-amber-50 px-5 py-4 sm:px-6">
                    <div class="flex items-start gap-3">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5 shrink-0 text-amber-600" aria-hidden="true" />
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-amber-900">
                                {{ __('Over the included allowance for this month') }}
                            </p>
                            <p class="mt-0.5 text-sm text-amber-800">
                                {{ __(':used jobs pushed against :included included.', [
                                    'used' => number_format($usedJobs),
                                    'included' => number_format($entitlement->monthlyIncludedJobs),
                                ]) }}
                                @if ($billingLive && $estimate['subtotal_cents'] > 0)
                                    {{ __('Estimated overage so far: $:amount.', ['amount' => number_format($estimate['subtotal_cents'] / 100, 2)]) }}
                                @else
                                    {{ __('Overage isn’t billed yet — this is here so the number is never a surprise later.') }}
                                @endif
                            </p>
                        </div>
                    </div>
                </div>
            @endif

            @if ($errors->isNotEmpty())
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <x-livewire-validation-errors />
                </div>
            @endif

            @if ($namespaces->isEmpty())
                <section class="border-b border-brand-ink/10 px-5 py-16 text-center sm:px-6">
                    <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-queue-list class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <h3 class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No queues yet') }}</h3>
                    <p class="mx-auto mt-1 max-w-lg text-sm leading-relaxed text-brand-moss">
                        {{ __('Create one for any Laravel app, wherever it runs.') }}
                    </p>
                    @if ($featureActive && $canManage)
                        <button
                            type="button"
                            wire:click="startCreate"
                            class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('New queue') }}
                        </button>
                    @endif
                    @unless ($featureActive)
                        <p class="mx-auto mt-3 max-w-md text-xs text-brand-moss">
                            {{ __('dply Queue isn’t enabled for this workspace yet.') }}
                        </p>
                    @endunless
                </section>
            @else
                <section class="divide-y divide-brand-ink/10">
                    @foreach ($namespaces as $namespace)
                        @php $depth = $depths[$namespace->id] ?? null; @endphp
                        <article wire:key="ns-{{ $namespace->id }}" class="relative px-5 py-5 transition-colors hover:bg-brand-sand/15 sm:px-6">
                            <a href="{{ route('organizations.queues.show', [$organization, $namespace]) }}" wire:navigate
                                class="absolute inset-0 z-0 rounded-[inherit]" aria-label="{{ __('View :name', ['name' => $namespace->name]) }}"></a>

                            <div class="relative z-10 flex flex-wrap items-start justify-between gap-4 pointer-events-none">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <h3 class="truncate text-base font-semibold text-brand-ink">{{ $namespace->name }}</h3>
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $statusTone[$namespace->status] ?? 'bg-brand-sand/55 text-brand-moss ring-brand-ink/10' }}">
                                            {{ ucfirst($namespace->status) }}
                                        </span>
                                        <x-heroicon-o-chevron-right class="h-4 w-4 shrink-0 text-brand-mist" />
                                    </div>
                                    <p class="mt-1 font-mono text-xs text-brand-moss">{{ $namespace->id }}</p>
                                    @if ($namespace->site)
                                        <p class="mt-0.5 text-xs text-brand-moss/80">
                                            {{ __('Wired to :site', ['site' => $namespace->site->name]) }}
                                        </p>
                                    @endif
                                    @if ($namespace->error_message)
                                        <p class="mt-2 rounded-md bg-red-50 px-2 py-1 text-xs text-red-700">{{ $namespace->error_message }}</p>
                                    @endif
                                </div>

                                <div class="text-right">
                                    @if ($depth)
                                        <p class="text-sm font-semibold text-brand-forest tabular-nums">
                                            {{ number_format($depth['total']) }} {{ trans_choice('job|jobs', $depth['total']) }}
                                        </p>
                                        <p class="mt-0.5 text-xs text-brand-moss tabular-nums">
                                            {{ __(':pending pending · :delayed delayed · :reserved in flight', [
                                                'pending' => number_format($depth['pending']),
                                                'delayed' => number_format($depth['delayed']),
                                                'reserved' => number_format($depth['reserved']),
                                            ]) }}
                                        </p>
                                    @else
                                        <p class="text-xs text-brand-mist">{{ __('Depth unavailable') }}</p>
                                    @endif
                                </div>
                            </div>

                            @if ($canManage)
                                <div class="relative z-10 mt-4 flex flex-wrap items-center gap-2 border-t border-brand-ink/10 pt-4 pointer-events-none">
                                    <div class="ml-auto flex items-center gap-2 pointer-events-auto">
                                        <x-secondary-button type="button" wire:click="togglePause('{{ $namespace->id }}')" class="text-xs">
                                            @if ($namespace->status === \App\Modules\Queue\Models\QueueNamespace::STATUS_PAUSED)
                                                <x-heroicon-o-play class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                {{ __('Resume') }}
                                            @else
                                                <x-heroicon-o-pause class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                {{ __('Pause') }}
                                            @endif
                                        </x-secondary-button>
                                        <button type="button" wire:click="confirmDelete('{{ $namespace->id }}')" class="inline-flex items-center gap-1.5 text-xs font-medium text-red-600 hover:text-red-700">
                                            <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Delete') }}
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </section>
            @endif
        </x-organization-shell>
    </div>

    {{-- Create --}}
    <x-modal name="queue-create-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        <form wire:submit="createNamespace">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-queue-list class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New queue') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Create a managed queue') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">
                        {{ __('There is nothing to provision — the queue exists the moment you create it, and its first credential is shown once on the next screen.') }}
                    </p>
                </div>
            </div>

            <div class="space-y-4 px-6 py-6">
                <div>
                    <x-input-label for="createName" :value="__('Name')" />
                    <x-text-input id="createName" wire:model="createName" type="text" class="mt-1 block w-full" maxlength="120" autofocus />
                    <p class="mt-1 text-xs text-brand-moss">{{ __('For you to recognise it by — “orders”, “staging”, an app name.') }}</p>
                </div>
            </div>

            <div class="flex justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="cancelCreate">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createNamespace">
                    <span wire:loading.remove wire:target="createNamespace">{{ __('Create queue') }}</span>
                    <span wire:loading wire:target="createNamespace">{{ __('Creating…') }}</span>
                </x-primary-button>
            </div>
        </form>
    </x-modal>

    {{-- Delete --}}
    <x-modal name="queue-delete-modal" :show="false" maxWidth="lg" overlayClass="bg-brand-ink/30" focusable>
        @if ($deletingNamespace)
            <form wire:submit="deleteNamespace">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-red-600">{{ __('Delete queue') }}</p>
                        <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $deletingNamespace->name }}</h2>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">
                            {{ __('Every job still queued is destroyed, and every credential is revoked immediately. Any app pushing to this queue starts failing.') }}
                        </p>
                    </div>
                </div>

                <div class="space-y-4 px-6 py-6">
                    <div>
                        <x-input-label for="deleteConfirmation" :value="__('Type :name to confirm', ['name' => $deletingNamespace->name])" />
                        <x-text-input id="deleteConfirmation" wire:model="deleteConfirmation" type="text" class="mt-1 block w-full font-mono" autocomplete="off" />
                    </div>
                </div>

                <div class="flex justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                    <x-secondary-button type="button" wire:click="cancelDelete">{{ __('Cancel') }}</x-secondary-button>
                    <x-danger-button type="submit" wire:loading.attr="disabled" wire:target="deleteNamespace">
                        {{ __('Delete queue') }}
                    </x-danger-button>
                </div>
            </form>
        @endif
    </x-modal>
</div>
