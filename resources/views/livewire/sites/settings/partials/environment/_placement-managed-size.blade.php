@php
    $managedEngine = filled($bindingForm['engine'] ?? null)
        ? (string) $bindingForm['engine']
        : ($bindingModalType === 'redis' ? 'redis' : 'postgres');
    $managedSizes = $this->managedDatabaseSizes($managedEngine);
    $selectedSize = \App\Models\CloudDatabase::resolveSizeSlug((string) ($bindingForm['size'] ?? 'small'));
    $sizeGroups = collect($managedSizes)->groupBy('group');
@endphp
<div>
    <x-input-label for="binding_managed_size" :value="__('Plan')" />
    <select id="binding_managed_size" wire:model.live="bindingForm.size" class="dply-input mt-1" @disabled(! empty($managedSizeDisabled))>
        @forelse ($sizeGroups as $group => $sizes)
            <optgroup label="{{ $group }}">
                @foreach ($sizes as $size)
                    <option value="{{ $size['value'] }}" @selected($selectedSize === $size['value'])>{{ $size['label'] }}</option>
                @endforeach
            </optgroup>
        @empty
            <option value="" disabled>{{ __('Could not load plans from DigitalOcean') }}</option>
        @endforelse
    </select>
    @if ($managedSizes === [])
        @unless (\App\Support\Servers\ManagedDatabaseCatalogFailure::isAuthFailure())
            <p class="mt-1.5 text-xs font-medium text-amber-700">{{ $this->managedDatabaseCatalogError() ?? __('Could not load managed-database plans from the provider. Reconnect the credential, or pick a different placement.') }}</p>
        @endunless
    @else
        <p class="mt-1.5 text-xs text-brand-moss">{{ __('Every plan DigitalOcean currently lists for this engine on a single node.') }}</p>
    @endif
</div>
