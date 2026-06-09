@php
    $s3Providers = \App\Livewire\Servers\WorkspaceDatabases::S3_BACKUP_PROVIDERS;
    $hasDestinations = $backupS3Destinations->isNotEmpty();
@endphp
<x-modal
    name="backup-database-modal"
    :show="false"
    maxWidth="2xl"
    overlayClass="bg-brand-ink/30"
    panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
    focusable
>
    <form wire:submit="runDatabaseBackup" class="flex min-h-0 flex-1 flex-col">
        <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
            <x-icon-badge>
                <x-heroicon-o-cloud-arrow-up class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Back up') }}</p>
                <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ $backupModalDatabase?->name ?? __('Database') }}</h2>
                <p class="mt-1 text-sm leading-6 text-brand-moss">
                    {{ __('Dumps the database over SSH and stores it where you choose. S3 uploads go straight from the server to your bucket via a presigned URL.') }}
                </p>
            </div>
        </div>

        <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
            {{-- Destination chooser --}}
            <fieldset class="space-y-3">
                <legend class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Where should this backup go?') }}</legend>

                @if ($hasDestinations)
                    <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 hover:bg-brand-sand/20 has-[:checked]:border-brand-sage has-[:checked]:bg-brand-sand/30">
                        <input type="radio" wire:model.live="backup_destination_mode" value="existing" class="mt-0.5 border-brand-ink/30 text-brand-forest focus:ring-brand-sage" />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-brand-ink">{{ __('Existing S3 destination') }}</span>
                            <span class="block text-xs text-brand-moss">{{ __('Reuse a backup destination already configured for your organization.') }}</span>
                        </span>
                    </label>
                @endif

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 hover:bg-brand-sand/20 has-[:checked]:border-brand-sage has-[:checked]:bg-brand-sand/30">
                    <input type="radio" wire:model.live="backup_destination_mode" value="new" class="mt-0.5 border-brand-ink/30 text-brand-forest focus:ring-brand-sage" />
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-brand-ink">{{ __('New S3 destination') }}</span>
                        <span class="block text-xs text-brand-moss">{{ __('Enter S3-compatible credentials (AWS S3, Custom S3, or DigitalOcean Spaces). Saved to your org for reuse.') }}</span>
                    </span>
                </label>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 hover:bg-brand-sand/20 has-[:checked]:border-brand-sage has-[:checked]:bg-brand-sand/30">
                    <input type="radio" wire:model.live="backup_destination_mode" value="server" class="mt-0.5 border-brand-ink/30 text-brand-forest focus:ring-brand-sage" />
                    <span class="min-w-0">
                        <span class="block text-sm font-medium text-brand-ink">{{ __('Server default') }}</span>
                        <span class="block text-xs text-brand-moss">{{ __('Use this server\'s configured default — the server disk (/var/lib/dply/database-backups) unless a default destination is set in settings.') }}</span>
                    </span>
                </label>
            </fieldset>

            {{-- Existing destination picker --}}
            @if ($backup_destination_mode === 'existing' && $hasDestinations)
                <div>
                    <x-input-label for="backup_destination_id" :value="__('Destination')" />
                    <select id="backup_destination_id" wire:model="backup_destination_id" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                        @foreach ($backupS3Destinations as $destination)
                            <option value="{{ $destination->id }}">{{ $destination->name }} — {{ \App\Models\BackupConfiguration::labelForProvider($destination->provider) }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('backup_destination_id')" class="mt-1" />
                </div>
            @endif

            {{-- Inline new-destination form --}}
            @if ($backup_destination_mode === 'new')
                <div class="space-y-4 rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <div>
                        <x-input-label for="backup_dest_name" :value="__('Destination name')" />
                        <x-text-input id="backup_dest_name" wire:model="backupDestinationForm.name" class="mt-1 block w-full text-sm" placeholder="{{ __('e.g. Production backups (S3)') }}" />
                        <x-input-error :messages="$errors->get('backupDestinationForm.name')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="backup_dest_provider" :value="__('Provider')" />
                        <select id="backup_dest_provider" wire:model.live="backupDestinationForm.provider" class="mt-1 block w-full rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                            @foreach ($s3Providers as $provider)
                                <option value="{{ $provider }}">{{ \App\Models\BackupConfiguration::labelForProvider($provider) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('backupDestinationForm.provider')" class="mt-1" />
                    </div>

                    @include('livewire.settings.partials.backup-provider-fields', [
                        'formKey' => 'backupDestinationForm',
                        'form' => $backupDestinationForm,
                    ])
                </div>
            @endif
        </div>

        <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
            <x-secondary-button type="button" wire:click="closeBackupModal">{{ __('Cancel') }}</x-secondary-button>
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="runDatabaseBackup"
                class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
            >
                <span wire:loading.remove wire:target="runDatabaseBackup" class="inline-flex items-center gap-2">
                    <x-heroicon-o-cloud-arrow-up class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ $backup_destination_mode === 'server' ? __('Back up') : __('Back up to S3') }}
                </span>
                <span wire:loading wire:target="runDatabaseBackup" class="inline-flex items-center gap-2 whitespace-nowrap">
                    <x-spinner variant="cream" size="sm" />
                    {{ __('Queueing…') }}
                </span>
            </button>
        </div>
    </form>
</x-modal>
