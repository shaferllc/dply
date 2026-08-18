<x-modal name="link-organization-secret-modal" :show="$showLinkOrganizationSecretModal" maxWidth="lg" overlayClass="bg-brand-ink/40">
    <div class="border-b border-brand-ink/10 px-6 py-5">
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Link an organization secret') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Applies on the next deploy. A site can only link one secret per key.') }}</p>
    </div>

    <div class="space-y-3 px-6 py-5">
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <x-input-label for="linkSecretSearch" :value="__('Search')" />
                <x-text-input id="linkSecretSearch" wire:model.live.debounce.300ms="linkSecretSearch" class="mt-1 block w-full" placeholder="{{ __('Key or note') }}" />
            </div>
            <div>
                <x-input-label for="linkSecretWorkspaceId" :value="__('Project filter')" />
                <select id="linkSecretWorkspaceId" wire:model.live="linkSecretWorkspaceId" class="dply-input mt-1 w-full">
                    <option value="">{{ __('All projects') }}</option>
                    @foreach ($this->linkSecretWorkspaceOptions() as $workspace)
                        <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        @php $linkable = $this->linkableOrganizationSecretRows(); @endphp
        @if ($linkable === [])
            <p class="text-sm text-brand-moss">{{ __('No secrets match. Create one on Organization → Secrets.') }}</p>
        @else
            <ul class="divide-y divide-brand-ink/8 rounded-xl border border-brand-ink/10">
                @foreach ($linkable as $row)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5" wire:key="linkable-secret-{{ $row['id'] }}">
                        <div class="min-w-0">
                            <p class="font-mono text-sm font-semibold text-brand-ink">{{ $row['key'] }}</p>
                            <p class="text-xs text-brand-moss">{{ $row['notes'] ?: __('No note') }}</p>
                            @if ($row['binding_owned'])
                                <p class="mt-0.5 text-xs text-amber-800">{{ __('A connected resource already owns this key. The binding wins unless you override it in the site .env.') }}</p>
                            @endif
                        </div>
                        @if ($row['already_linked'])
                            <span class="text-xs font-semibold text-brand-mist">{{ __('Linked') }}</span>
                        @elseif ($row['key_taken'])
                            <span class="text-xs font-semibold text-brand-mist">{{ __('Key already linked') }}</span>
                        @else
                            <x-primary-button type="button" wire:click="linkOrganizationSecret('{{ $row['id'] }}')" wire:loading.attr="disabled" wire:target="linkOrganizationSecret">
                                {{ __('Link') }}
                            </x-primary-button>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="flex justify-end border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-4">
        <x-secondary-button type="button" wire:click="closeLinkOrganizationSecretModal" x-on:click="$dispatch('close')">{{ __('Close') }}</x-secondary-button>
    </div>
</x-modal>
