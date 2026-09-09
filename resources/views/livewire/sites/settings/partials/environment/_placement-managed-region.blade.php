@php
    $managedEngine = filled($bindingForm['engine'] ?? null)
        ? (string) $bindingForm['engine']
        : ($bindingModalType === 'redis' ? 'redis' : 'postgres');
    $managedRegions = $this->managedDatabaseRegions($managedEngine);
    $selectedRegion = (string) ($bindingForm['region'] ?? '');
    if ($selectedRegion === '' && filled($placementRegion ?? null)) {
        $selectedRegion = (string) $placementRegion;
    }
@endphp
@if ($managedRegions !== [])
    <div>
        <x-input-label for="binding_managed_region" :value="__('Region')" />
        <select id="binding_managed_region" wire:model.live="bindingForm.region" class="dply-input mt-1">
            @foreach ($managedRegions as $region)
                <option value="{{ $region['value'] }}" @selected($selectedRegion === $region['value'])>{{ $region['label'] }}</option>
            @endforeach
        </select>
        <p class="mt-1.5 text-xs text-brand-moss">{{ __('Every datacenter DigitalOcean lists for this engine.') }}</p>
    </div>
@else
    @if (\App\Support\Servers\ManagedDatabaseCatalogFailure::isAuthFailure())
        @include('livewire.sites.settings.partials.environment._provider-auth-failure')
    @else
        <p class="text-xs font-medium text-amber-700">{{ $this->managedDatabaseCatalogError() ?? __('Could not load managed-database regions from the provider. Reconnect the credential, or pick a different placement.') }}</p>
    @endif
@endif
