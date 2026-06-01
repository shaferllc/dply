@php
    /** @var \App\Models\ServerCacheService $row */
    /** @var \Illuminate\Support\Collection<int, \App\Models\Server> $availableReplicaServers */
    $availableReplicaServers = $availableReplicaServers ?? collect();
@endphp

<x-modal name="add-replica-modal" maxWidth="lg" overlayClass="bg-brand-ink/40">
    <form wire:submit="submitAddReplica" class="space-y-4 p-6 sm:p-8">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Replication') }}</p>
            <h3 class="mt-0.5 text-lg font-semibold text-brand-ink">{{ __('Add a replica') }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Picks an org-owned redis/valkey server, exposes this master to its IP via a firewall rule, sets REPLICAOF on the target, and waits for master_link_status=up.') }}
            </p>
        </div>

        <div>
            <x-input-label for="addReplicaTargetServerId" :value="__('Target server')" />
            <select
                id="addReplicaTargetServerId"
                wire:model="addReplicaTargetServerId"
                class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm"
            >
                <option value="">— {{ __('Select server') }} —</option>
                @foreach ($availableReplicaServers as $candidate)
                    <option value="{{ $candidate->id }}">{{ $candidate->name }} · {{ $candidate->ip_address ?? '—' }}</option>
                @endforeach
            </select>
            @if ($availableReplicaServers->isEmpty())
                <p class="mt-1 text-[11px] text-amber-700">{{ __('No other redis/valkey-role servers are READY in your organization.') }}</p>
            @endif
            <x-input-error :messages="$errors->get('addReplicaTargetServerId')" class="mt-1" />
        </div>

        <label class="flex items-start gap-2 text-xs text-brand-moss">
            <input
                type="checkbox"
                wire:model="addReplicaWipeAcknowledged"
                class="mt-0.5 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30"
            />
            <span>{{ __('I understand that REPLICAOF on the target will wipe any data it currently holds. The target should be empty or expendable.') }}</span>
        </label>
        <x-input-error :messages="$errors->get('addReplicaWipeAcknowledged')" />

        <div class="rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-2 text-xs text-amber-900">
            <p class="font-semibold">{{ __('What happens on submit:') }}</p>
            <ol class="ml-4 mt-1 list-decimal space-y-0.5">
                <li>{{ __('Open firewall on this master for the target\'s IP /32.') }}</li>
                <li>{{ __('CONFIG SET masterauth + REPLICAOF on the target.') }}</li>
                <li>{{ __('CONFIG REWRITE on the target so the link survives restart.') }}</li>
                <li>{{ __('Poll INFO replication for up to 30s until master_link_status=up; rollback otherwise.') }}</li>
            </ol>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
            <button type="button" x-on:click="$dispatch('close')" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">
                {{ __('Cancel') }}
            </button>
            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="submitAddReplica">
                <span wire:loading.remove wire:target="submitAddReplica">{{ __('Attach replica') }}</span>
                <span wire:loading wire:target="submitAddReplica">{{ __('Attaching…') }}</span>
            </x-primary-button>
        </div>
    </form>
</x-modal>
