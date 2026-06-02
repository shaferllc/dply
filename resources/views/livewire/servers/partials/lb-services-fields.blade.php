{{-- Load balancer service rows. Expects: $lb_services, addLbServiceRow / removeLbServiceRow --}}
<div>
    <div class="mb-3 flex items-center justify-between">
        <p class="text-sm font-semibold text-brand-ink">{{ __('Services') }}</p>
        <button type="button" wire:click="addLbServiceRow" class="text-xs font-medium text-brand-sage hover:underline">
            + {{ __('Add service') }}
        </button>
    </div>
    <div class="space-y-3">
        @foreach ($lb_services as $i => $svc)
            <div class="grid gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/10 p-4 sm:grid-cols-4 sm:items-end">
                <div>
                    <x-input-label :value="__('Protocol')" />
                    <select wire:model="lb_services.{{ $i }}.protocol" class="dply-input mt-1 block w-full">
                        <option value="http">HTTP</option>
                        <option value="https">HTTPS</option>
                        <option value="tcp">TCP</option>
                    </select>
                </div>
                <div>
                    <x-input-label :value="__('Listen port')" />
                    <x-text-input wire:model="lb_services.{{ $i }}.listen_port" class="mt-1 block w-full font-mono" placeholder="80" />
                    @error("lb_services.{$i}.listen_port") <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                </div>
                <div>
                    <x-input-label :value="__('Destination port')" />
                    <x-text-input wire:model="lb_services.{{ $i }}.destination_port" class="mt-1 block w-full font-mono" placeholder="8080" />
                    @error("lb_services.{$i}.destination_port") <p class="mt-1 text-xs text-rose-700">{{ $message }}</p> @enderror
                </div>
                <div class="flex items-end">
                    @if (count($lb_services) > 1)
                        <button type="button" wire:click="removeLbServiceRow({{ $i }})" class="text-xs font-medium text-rose-600 hover:underline">{{ __('Remove') }}</button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
