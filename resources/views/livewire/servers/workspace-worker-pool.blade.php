<x-server-workspace-layout
    :server="$server"
    active="worker-pool"
    :title="__('Worker Pool')"
    :description="__('Clone this worker and scale background capacity. The pool keeps one primary (scheduler owner); the rest are queue-worker replicas.')"
    :context-site="null"
>
    @if (! $pool)
        {{-- No pool yet: offer to create one from this worker. --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-server-stack class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Scaling') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Create a worker pool') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Turn this worker into the primary of a pool. You can then scale to N workers — each clone replays this server’s sites and joins the same queue.') }}
                    </p>
                </div>
            </div>

            @if ($server->isWorkerHost())
                <form wire:submit="createPool" class="space-y-5 px-6 py-6 sm:px-7">
                    <div>
                        <x-input-label for="pool_name" :value="__('Pool name')" />
                        <x-text-input id="pool_name" wire:model="pool_name" class="mt-2 block w-full text-sm" />
                    </div>
                    <div class="flex justify-end">
                        <x-primary-button type="submit">{{ __('Create pool') }}</x-primary-button>
                    </div>
                </form>
            @else
                <div class="px-6 py-6 sm:px-7">
                    <p class="text-sm text-brand-moss">{{ __('This server is not a worker host, so it cannot start a worker pool. Worker pools are for servers with the worker role (queue_worker profile).') }}</p>
                </div>
            @endif
        </section>
    @else
        @php
            $active = $pool->activeMemberCount();
            $healthy = $members->filter(fn ($m) => $m->isReady() && in_array($m->poolMemberState(), [null, \App\Models\WorkerPool::MEMBER_ACTIVE], true) || $m->isPoolPrimary())->count();
        @endphp

        {{-- Scale control --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-arrows-pointing-out class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $pool->name }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Scale workers') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Desired :desired · active :active · healthy :healthy · max :max · status :status', [
                            'desired' => $pool->desired_count,
                            'active' => $active,
                            'healthy' => $healthy,
                            'max' => $pool->max_size,
                            'status' => $pool->status,
                        ]) }}
                    </p>
                </div>
            </div>

            <form wire:submit="scale" class="flex flex-wrap items-end gap-4 px-6 py-6 sm:px-7">
                <div>
                    <x-input-label for="desired_count" :value="__('Desired worker count (incl. primary)')" />
                    <x-text-input id="desired_count" type="number" min="1" :max="$pool->max_size" wire:model="desired_count" class="mt-2 block w-32 text-sm" />
                    <x-input-error :messages="$errors->get('desired_count')" class="mt-2" />
                </div>
                <div class="pb-0.5">
                    <x-primary-button type="submit">{{ __('Apply scale') }}</x-primary-button>
                </div>
                @php $delta = (int) $desired_count - $active; @endphp
                @if ($delta !== 0)
                    <p class="pb-1 text-xs text-brand-moss">
                        {{ $delta > 0
                            ? __('+:n server(s) will be provisioned (billable).', ['n' => $delta])
                            : __(':n server(s) will be drained and destroyed.', ['n' => abs($delta)]) }}
                    </p>
                @endif
            </form>
        </section>

        {{-- Members --}}
        <section class="dply-card mt-6 overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-4 sm:px-7">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('Members') }}</h2>
            </div>
            <div class="divide-y divide-brand-ink/10">
                @foreach ($members as $member)
                    <div class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 sm:px-7">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('servers.overview', $member) }}" wire:navigate class="text-sm font-semibold text-brand-ink hover:text-brand-forest hover:underline">{{ $member->name }}</a>
                                @if ($member->isPoolPrimary())
                                    <span class="rounded-full bg-brand-forest/10 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('Primary') }}</span>
                                @else
                                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Replica') }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-brand-moss">
                                {{ $member->region }} · {{ $member->size }} · {{ __('status') }}: {{ $member->status }}@if ($member->poolMemberState()) · {{ $member->poolMemberState() }} @endif
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            @if (! $member->isPoolPrimary())
                                <button type="button" wire:click="promote('{{ $member->id }}')" class="rounded-lg border border-brand-ink/15 px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Promote') }}</button>
                                <button type="button" wire:click="removeMember('{{ $member->id }}')" wire:confirm="{{ __('Drain and destroy this worker?') }}" class="rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50">{{ __('Remove') }}</button>
                            @else
                                <span class="text-xs text-brand-mist">{{ __('Promote another member to remove this one') }}</span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <p class="mt-4 text-xs text-brand-moss">
            {{ __('v1: same-region only. Clones join this server’s private network and replay its sites onto the same queue. Cross-region/provider scaling is planned (see spec).') }}
        </p>
    @endif
</x-server-workspace-layout>
