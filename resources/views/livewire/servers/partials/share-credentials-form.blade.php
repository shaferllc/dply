@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\ServerDatabase> $databases */
    $databases = $databases ?? collect();
    $orgAllowsCredentialShares = $orgAllowsCredentialShares ?? true;
@endphp
<div class="{{ $card ?? 'dply-card overflow-hidden' }} overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Share') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Share credentials (read-only link)') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Hand a tracked database\'s credentials to a teammate via a single-use link with an expiry and view cap.') }}</p>
        </div>
    </div>
    <div class="px-6 py-6 sm:px-7">
    @if (! $orgAllowsCredentialShares)
        <p class="mt-2 text-sm text-brand-moss">{{ __('Public credential share links are disabled for this organization.') }}</p>
    @elseif ($databases->isEmpty())
        <p class="mt-3 text-sm text-brand-moss">{{ __('Add a database to share its credentials.') }}</p>
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
