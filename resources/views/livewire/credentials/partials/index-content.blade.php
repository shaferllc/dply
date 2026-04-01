<x-livewire-validation-errors />

<nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
    <ol class="flex flex-wrap items-center gap-2">
        <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
        <li class="text-brand-mist" aria-hidden="true">/</li>
        <li><a href="{{ route('settings.index') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Settings') }}</a></li>
        <li class="text-brand-mist" aria-hidden="true">/</li>
        <li class="text-brand-ink font-medium">{{ __('Provider credentials') }}</li>
    </ol>
</nav>

<x-page-header
    :title="__('Provider credentials')"
    :description="__('Store encrypted API keys for the cloud providers your organization uses. Tokens are validated when possible. Use the list on the left to configure one provider at a time.')"
    flush
>
    @if ($organization)
        <x-slot name="actions">
            <x-badge tone="accent" class="normal-case tracking-normal text-xs">
                {{ __('Organization: :name', ['name' => $organization->name]) }}
            </x-badge>
        </x-slot>
    @endif
</x-page-header>

{{-- Mobile: jump to provider --}}
<div class="mb-6 lg:hidden">
    <x-input-label for="credentials_provider_picker" :value="__('Provider')" />
    <x-select id="credentials_provider_picker" wire:model.live="active_provider">
        @foreach ($providerNav as $group)
            <optgroup label="{{ $group['label'] }}">
                @foreach ($group['items'] as $item)
                    <option value="{{ $item['id'] }}">{{ $item['label'] }}</option>
                @endforeach
            </optgroup>
        @endforeach
    </x-select>
</div>

<div class="lg:grid lg:grid-cols-12 lg:gap-10 items-start">
    <aside class="hidden lg:block lg:col-span-4 xl:col-span-3">
        <div class="sticky top-24 rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden max-h-[calc(100vh-8rem)] flex flex-col">
            <div class="px-4 py-3 border-b border-brand-ink/10 bg-brand-sand/30">
                <p class="text-xs font-semibold uppercase tracking-wider text-brand-mist">{{ __('Providers') }}</p>
            </div>
            <nav class="overflow-y-auto p-2 space-y-4 text-sm" aria-label="{{ __('Cloud providers') }}">
                @foreach ($providerNav as $group)
                    <div>
                        <p class="px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-brand-mist">{{ $group['label'] }}</p>
                        <ul class="space-y-0.5 mt-1">
                            @foreach ($group['items'] as $item)
                                @php $count = $this->credentialCountFor($item['id']); @endphp
                                <li>
                                    <button
                                        type="button"
                                        wire:click="$set('active_provider', '{{ $item['id'] }}')"
                                        @class([
                                            'w-full text-left rounded-lg px-3 py-2 transition-colors flex items-center justify-between gap-2',
                                            'bg-brand-sand/60 text-brand-ink font-medium' => $active_provider === $item['id'],
                                            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $active_provider !== $item['id'],
                                        ])
                                    >
                                        <span class="truncate">{{ $item['label'] }}</span>
                                        @if ($count > 0)
                                            <span class="shrink-0 text-[10px] font-semibold tabular-nums rounded-full bg-brand-sage/25 text-brand-ink px-1.5 py-0.5">{{ $count }}</span>
                                        @endif
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </nav>
        </div>
    </aside>

    <div class="lg:col-span-8 xl:col-span-9 min-w-0 space-y-6">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-lg font-semibold text-brand-ink">{{ $activeProviderLabel }}</h2>
            <a href="{{ route('docs.connect-provider') }}" wire:navigate class="text-sm font-medium text-brand-sage hover:text-brand-ink hover:underline">{{ __('Setup guide') }}</a>
        </div>

        @include('livewire.credentials.panel', [
            'credentials' => $credentials,
            'digitalOceanOAuthConfigured' => filled(config('services.digitalocean_oauth.client_id')) && filled(config('services.digitalocean_oauth.client_secret')),
        ])
    </div>
</div>
