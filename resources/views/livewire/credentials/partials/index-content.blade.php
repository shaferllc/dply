<x-livewire-validation-errors />

@if ($organization)
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
        ['label' => __('Provider credentials'), 'icon' => 'server'],
    ]" />
@else
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Settings'), 'href' => route('settings.profile'), 'icon' => 'cog-6-tooth'],
        ['label' => __('Provider credentials'), 'icon' => 'server'],
    ]" />
@endif

<div class="space-y-8">
    <div class="dply-card overflow-hidden">
        <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
            <div class="lg:col-span-4">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Provider credentials') }}</h2>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                    {{ __('Store encrypted API keys for the cloud providers your organization uses. Tokens are validated when possible. Use the list on the left to configure one provider at a time.') }}
                </p>
            </div>
            <div class="lg:col-span-8 flex flex-wrap items-start justify-end gap-3">
                <a
                    href="{{ route('docs.connect-provider') }}"
                    wire:navigate
                    class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Provider setup guide') }}
                </a>
                @if ($organization)
                    <x-badge tone="accent" :caps="false" class="text-xs">
                        {{ __('Organization: :name', ['name' => $organization->name]) }}
                    </x-badge>
                @endif
            </div>
        </div>
    </div>

    {{-- Mobile: jump to provider --}}
    <div class="lg:hidden">
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
            <div class="sticky top-24 dply-card overflow-hidden max-h-[calc(100vh-8rem)] flex flex-col">
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
                                            <span class="flex min-w-0 flex-1 items-center gap-2">
                                                <x-credentials-provider-icon :provider="$item['id']" />
                                                <span class="truncate">{{ $item['label'] }}</span>
                                            </span>
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
            <h2 class="inline-flex items-center gap-2 text-lg font-semibold text-brand-ink">
                <x-credentials-provider-icon :provider="$active_provider" class="h-5 w-5 opacity-95" />
                {{ $activeProviderLabel }}
            </h2>

            @include('livewire.credentials.panel', [
                'credentials' => $credentials,
                'digitalOceanOAuthConfigured' => filled(config('services.digitalocean_oauth.client_id')) && filled(config('services.digitalocean_oauth.client_secret')),
            ])
        </div>
    </div>
</div>
