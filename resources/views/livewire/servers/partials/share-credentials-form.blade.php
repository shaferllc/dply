@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ServerDatabase> $databases */
    $databases = $databases ?? collect();
    $orgAllowsCredentialShares = $orgAllowsCredentialShares ?? true;
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} overflow-hidden">
    <x-workspace-panel-head
        dense
        icon="heroicon-o-share"
        :title="__('Share credentials (read-only link)')"
        :note="__('Hand a tracked database\'s credentials to a teammate via a single-use link with an expiry and view cap.')"
        class="border-b border-brand-ink/10"
    />
    <div class="px-4 py-3.5 sm:px-5">
    @if (! $orgAllowsCredentialShares)
        <x-empty-state
            compact
            borderless
            icon="heroicon-o-lock-closed"
            tone="amber"
            :title="__('Credential sharing disabled')"
            :description="__('Public credential share links are turned off for this organization. Ask an admin to enable them in organization settings.')"
        />
    @elseif ($databases->isEmpty())
        <x-empty-state
            compact
            borderless
            icon="heroicon-o-share"
            tone="sage"
            :title="__('No database to share yet')"
            :description="__('Create a tracked database on Basics, then generate a single-use read-only link with expiry and view limits.')"
        >
            <x-slot:actions>
                <button
                    type="button"
                    wire:click="setWorkspaceTab('databases')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                >
                    <x-heroicon-o-plus class="h-4 w-4" aria-hidden="true" />
                    {{ __('Go to Basics') }}
                </button>
            </x-slot:actions>
        </x-empty-state>
    @else
        <form wire:submit="createCredentialShare" class="mt-6 grid max-w-xl grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-input-label for="share_target_db_id" value="{{ __('Database') }}" />
                <select id="share_target_db_id" wire:model="share_target_db_id" wire:loading.attr="disabled" wire:target="createCredentialShare" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm">
                    <option value="">{{ __('Select…') }}</option>
                    @foreach ($databases as $sdb)
                        <option value="{{ $sdb->id }}">{{ $sdb->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('share_target_db_id')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="share_expires_hours" value="{{ __('Expires in (hours)') }}" />
                <x-text-input id="share_expires_hours" type="number" wire:model="share_expires_hours" wire:loading.attr="disabled" wire:target="createCredentialShare" class="mt-1 block w-full text-sm" min="1" max="720" />
            </div>
            <div>
                <x-input-label for="share_max_views" value="{{ __('Max views') }}" />
                <x-text-input id="share_max_views" type="number" wire:model="share_max_views" wire:loading.attr="disabled" wire:target="createCredentialShare" class="mt-1 block w-full text-sm" min="1" max="50" />
            </div>
            <div class="sm:col-span-2">
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="createCredentialShare">
                    <span wire:loading.remove wire:target="createCredentialShare">{{ __('Create share link') }}</span>
                    <span wire:loading wire:target="createCredentialShare">{{ __('Creating…') }}</span>
                </x-primary-button>
            </div>
        </form>
    @endif
    </div>
</div>
