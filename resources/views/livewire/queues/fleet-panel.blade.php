@php
    $btnOutline = 'dply-btn dply-btn-xs dply-btn-outline';
@endphp

{{-- Polled, not static: running-worker counts change on their own as the
     autoscaler and the push-wake path start and stop containers. This is the
     one place in the product where "what is dply doing right now" is
     answerable, so it has to stay current while someone watches it. --}}
<div class="border-t border-brand-ink/10" wire:poll.5s.visible>
    <x-workspace-panel-head
        dense
        icon="heroicon-o-cpu-chip"
        :title="__('Managed workers')"
        :count="count($this->fleets) > 0 ? (string) count($this->fleets) : __('none')"
        :note="__('dply-owned workers that drain this queue and scale with the work waiting on it. Sizing is memory per worker and a worker range — everything else is managed.')"
    >
        @if ($canManage && ! $creating)
            <x-slot:actions>
                <button type="button" wire:click="startCreating" class="{{ $btnOutline }}">
                    <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('New fleet') }}
                </button>
            </x-slot:actions>
        @endif
    </x-workspace-panel-head>

    <div class="space-y-3 px-3 py-3 sm:px-4">
        {{-- The substrate defaults to `fake`, which starts nothing. Saying so
             here is the difference between "my fleet is broken" and "this
             deployment has no worker runtime configured yet". --}}
        @unless ($runtimeConfigured)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                <p class="font-semibold">{{ __('No worker runtime is configured on this deployment.') }}</p>
                <p class="mt-0.5">{{ __('Fleets can be created and sized, but no containers will start until DPLY_QUEUE_FLEET_RUNTIME names a real substrate.') }}</p>
            </div>
        @endunless

        @forelse ($this->fleets as $fleet)
            <div @class([
                'rounded-lg border px-3 py-2.5',
                'border-brand-ink/10 bg-brand-sand/30' => $fleet['status'] === 'active',
                'border-amber-200 bg-amber-50/50' => $fleet['status'] !== 'active',
            ])>
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-mono text-sm font-semibold text-brand-ink">{{ $fleet['queue'] }}</span>

                        <span @class([
                            'inline-flex items-center rounded-full px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] ring-1 ring-inset',
                            'bg-white text-brand-moss ring-brand-ink/10' => $fleet['class'] === 'flex',
                            'bg-brand-forest/10 text-brand-forest ring-brand-forest/20' => $fleet['class'] === 'pro',
                        ])>{{ $fleet['class'] }}</span>

                        @if ($fleet['status'] !== 'active')
                            <span class="inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-[0.14em] text-amber-900 ring-1 ring-inset ring-amber-300">{{ __('paused') }}</span>
                        @endif
                    </div>

                    @if ($canManage)
                        <div class="flex shrink-0 flex-wrap items-center gap-1.5">
                            <button type="button" wire:click="edit('{{ $fleet['id'] }}')" class="{{ $btnOutline }}">{{ __('Resize') }}</button>
                            <button type="button" wire:click="togglePause('{{ $fleet['id'] }}')" class="{{ $btnOutline }}">
                                {{ $fleet['status'] === 'active' ? __('Pause') : __('Resume') }}
                            </button>
                            <button type="button" wire:click="delete('{{ $fleet['id'] }}')"
                                    wire:confirm="{{ __('Delete this fleet? Its workers stop on the next tick. Usage already recorded is kept.') }}"
                                    class="{{ $btnOutline }} text-rose-700 ring-rose-200 hover:bg-rose-50">{{ __('Delete') }}</button>
                        </div>
                    @endif
                </div>

                {{-- Running is read from the worker rows, desired from the
                     autoscaler. The gap between them is the only visible sign
                     that a substrate is refusing to place containers. --}}
                <dl class="mt-2 flex flex-wrap items-baseline gap-x-4 gap-y-1 text-2xs text-brand-mist">
                    <div class="inline-flex items-baseline gap-1.5">
                        <dt class="font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Workers') }}</dt>
                        <dd class="text-xs tabular-nums text-brand-ink">
                            {{ $fleet['running'] }} / {{ $fleet['desired'] }}
                            <span class="text-brand-mist">({{ $fleet['min_workers'] }}–{{ $fleet['max_workers'] }})</span>
                        </dd>
                    </div>

                    <div class="inline-flex items-baseline gap-1.5">
                        <dt class="font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Memory') }}</dt>
                        <dd class="text-xs tabular-nums text-brand-ink">{{ number_format($fleet['memory_mib']) }} MiB</dd>
                    </div>

                    <div class="inline-flex items-baseline gap-1.5">
                        <dt class="font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Waiting') }}</dt>
                        <dd class="text-xs tabular-nums text-brand-ink">
                            {{ $fleet['depth'] === null ? __('unknown') : number_format($fleet['depth']['pending']) }}
                        </dd>
                    </div>

                    @if ($fleet['avg_job_seconds'])
                        <div class="inline-flex items-baseline gap-1.5">
                            <dt class="font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Job') }}</dt>
                            <dd class="text-xs tabular-nums text-brand-ink">~{{ number_format((float) $fleet['avg_job_seconds'], 2) }}s</dd>
                        </div>
                    @endif
                </dl>

                @if ($fleet['image'] === '')
                    <p class="mt-1.5 text-2xs text-amber-800">{{ __('No worker image set for this fleet — nothing will start until one is.') }}</p>
                @endif

                @if ($editingId === $fleet['id'])
                    <div class="mt-3 grid gap-2 border-t border-brand-ink/10 pt-3 sm:grid-cols-3">
                        <label class="block">
                            <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Memory (MiB)') }}</span>
                            <input type="number" wire:model="memory_mib" class="dply-input mt-1 w-full" min="256" step="256" />
                            @error('memory_mib') <span class="mt-0.5 block text-2xs text-rose-700">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Min workers') }}</span>
                            <input type="number" wire:model="min_workers" class="dply-input mt-1 w-full" min="0" />
                            @error('min_workers') <span class="mt-0.5 block text-2xs text-rose-700">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Max workers') }}</span>
                            <input type="number" wire:model="max_workers" class="dply-input mt-1 w-full" min="1" />
                            @error('max_workers') <span class="mt-0.5 block text-2xs text-rose-700">{{ $message }}</span> @enderror
                        </label>

                        <div class="flex items-center gap-2 sm:col-span-3">
                            <button type="button" wire:click="save" class="dply-btn dply-btn-xs">{{ __('Save size') }}</button>
                            <button type="button" wire:click="cancelEdit" class="{{ $btnOutline }}">{{ __('Cancel') }}</button>
                        </div>
                    </div>
                @endif
            </div>
        @empty
            @unless ($creating)
                <p class="text-xs text-brand-moss">
                    {{ __('No managed workers yet. Without a fleet, this queue holds jobs until something you run drains it.') }}
                </p>
            @endunless
        @endforelse

        @if ($creating)
            <div class="rounded-lg border border-brand-ink/10 bg-white px-3 py-3">
                <p class="text-sm font-semibold text-brand-ink">{{ __('New fleet') }}</p>
                <p class="mt-0.5 text-xs text-brand-moss">
                    {{ __('One fleet drains one queue name. Flex sleeps at zero and wakes on the next job; Pro always keeps a worker running and has no job runtime ceiling.') }}
                </p>

                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Queue name') }}</span>
                        <input type="text" wire:model="queue" class="dply-input mt-1 w-full font-mono" maxlength="39" />
                        @error('queue') <span class="mt-0.5 block text-2xs text-rose-700">{{ $message }}</span> @enderror
                    </label>

                    <label class="block">
                        <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Compute class') }}</span>
                        <select wire:model.live="class" class="dply-input mt-1 w-full">
                            <option value="flex">{{ __('Flex — sleeps at zero') }}</option>
                            <option value="pro">{{ __('Pro — always on') }}</option>
                        </select>
                    </label>

                    <label class="block">
                        <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Memory per worker (MiB)') }}</span>
                        <input type="number" wire:model="memory_mib" class="dply-input mt-1 w-full" min="256" step="256" />
                        @error('memory_mib') <span class="mt-0.5 block text-2xs text-rose-700">{{ $message }}</span> @enderror
                    </label>

                    <div class="grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Min') }}</span>
                            <input type="number" wire:model="min_workers" class="dply-input mt-1 w-full" min="0" />
                            @error('min_workers') <span class="mt-0.5 block text-2xs text-rose-700">{{ $message }}</span> @enderror
                        </label>
                        <label class="block">
                            <span class="text-2xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Max') }}</span>
                            <input type="number" wire:model="max_workers" class="dply-input mt-1 w-full" min="1" />
                            @error('max_workers') <span class="mt-0.5 block text-2xs text-rose-700">{{ $message }}</span> @enderror
                        </label>
                    </div>
                </div>

                <p class="mt-2 text-2xs text-brand-mist">
                    {{ __('Choosing a maximum is about what your database and third-party APIs can absorb at once — start conservative and raise it.') }}
                </p>

                <div class="mt-3 flex items-center gap-2">
                    <button type="button" wire:click="create" class="dply-btn dply-btn-xs">{{ __('Create fleet') }}</button>
                    <button type="button" wire:click="cancelCreating" class="{{ $btnOutline }}">{{ __('Cancel') }}</button>
                </div>
            </div>
        @endif
    </div>
</div>
