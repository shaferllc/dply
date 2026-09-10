{{--
    wp-config.php editor. Admin/owner only (the tab is hidden otherwise): the
    file holds DB_PASSWORD and the salts, and runs on every request. Saves are
    syntax-checked, refused if the file changed on disk, and audit-logged.
--}}
<div class="border-b border-brand-ink/10 last:border-b-0">
    <div class="flex flex-wrap items-start justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/[0.18] px-3 py-2.5 sm:px-4">
        <div class="min-w-0">
            <h3 class="text-sm font-semibold text-brand-ink">{{ __('wp-config.php') }}</h3>
            <p class="mt-0.5 max-w-2xl text-xs leading-relaxed text-brand-moss">{{ __('Edit the live wp-config.php. Saves are checked for PHP syntax first, refused if the file changed since you opened it, and recorded in the audit log.') }}</p>
            @if ($wpConfigPath)
                <p class="mt-1 break-all font-mono text-2xs text-brand-mist">{{ $wpConfigPath }}</p>
            @endif
        </div>
        @if ($canDestroy)
            <div class="flex items-center gap-2">
                <x-spinner-button size="xs" variant="secondary" type="button" icon="heroicon-o-arrow-path" target="loadWpConfig" wire:click="loadWpConfig">{{ $wpConfigPath ? __('Reload') : __('Open wp-config.php') }}</x-spinner-button>
                @if ($wpConfigPath)
                    <x-spinner-button size="xs" variant="primary" type="button" icon="heroicon-o-check" target="saveWpConfig" wire:click="saveWpConfig">{{ __('Save') }}</x-spinner-button>
                @endif
            </div>
        @endif
    </div>

    @if (! $canDestroy)
        <p class="px-3 py-2.5 text-xs text-brand-moss sm:px-4">{{ __('Admin or owner role required to open wp-config.php — it holds the database password and salts.') }}</p>
    @else
        @if ($coreManagedByComposer)
            <p class="border-b border-brand-ink/10 px-3 py-2 text-xs text-brand-moss sm:px-4">{{ __('Bedrock site: settings live in .env and config/application.php — this wp-config.php only loads them.') }}</p>
        @endif

        @error('wpconfig')
            <p class="border-b border-rose-200/70 bg-rose-50/60 px-3 py-2 text-xs text-rose-700 sm:px-4">{{ $message }}</p>
        @enderror

        @if ($wpConfigPath && $wpConfigWillBeOverwrittenOnDeploy)
            <div class="border-b border-amber-200/70 bg-amber-50/70 px-3 py-2 text-xs text-amber-900 sm:px-4">
                {{ __('The next deploy will overwrite this file. Change it in your repository to keep it, or (on zero-downtime sites) under shared/.') }}
                @if ($wpConfigPendingOverwrite)
                    <div class="mt-2 flex gap-2">
                        <x-spinner-button size="xs" variant="primary" type="button" target="saveWpConfig" wire:click="saveWpConfig(true)">{{ __('Save anyway') }}</x-spinner-button>
                        <button type="button" wire:click="$set('wpConfigPendingOverwrite', false)" class="rounded-md border border-brand-ink/15 px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/40">{{ __('Back to editor') }}</button>
                    </div>
                @endif
            </div>
        @endif

        @if ($wpConfigPath)
            <div class="flex h-[60vh] flex-col px-3 pb-3 sm:px-4">
                @include('livewire.servers.partials.configuration.code-editor', ['path' => $wpConfigPath])
            </div>
        @endif
    @endif
</div>
