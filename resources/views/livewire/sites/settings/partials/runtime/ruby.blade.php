@php
    $detectedFramework = strtolower((string) ($site->resolvedRuntimeAppDetection()['framework'] ?? ''));
    $isRailsLike = $detectedFramework === 'rails' || $site->shouldShowRailsRuntimeSettings();
    $panelBody = 'px-5 py-3 sm:px-6';
    $fieldHelp = 'mt-1 text-xs text-brand-moss';
@endphp

@if ($isRailsLike)
    <form wire:submit="saveRuntimePreferences" class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-command-line"
            :title="__('Ruby / Rails')"
            :note="__('Stored on the site for deploy scripts and operator reference.')"
        >
            <x-slot:actions>
                <x-primary-button size="sm" type="submit" wire:loading.attr="disabled" wire:target="saveRuntimePreferences">
                    <span wire:loading.remove wire:target="saveRuntimePreferences">{{ __('Save') }}</span>
                    <span wire:loading wire:target="saveRuntimePreferences">{{ __('Saving…') }}</span>
                </x-primary-button>
            </x-slot:actions>
        </x-workspace-panel-head>

        <div class="{{ $panelBody }}">
            <div class="max-w-md">
                <x-input-label for="rails_env" :value="__('RAILS_ENV')" class="!text-xs" />
                <x-text-input id="rails_env" wire:model="rails_env" class="mt-1 block w-full font-mono text-sm" placeholder="production" />
                <p class="{{ $fieldHelp }}">{{ __('Align with Puma/Thruster and systemd. Also appears under Deploy → Rollout and web server.') }}</p>
                <x-input-error :messages="$errors->get('rails_env')" class="mt-1" />
            </div>
        </div>
    </form>
@else
    <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-command-line"
            :title="__('Ruby runtime')"
            :note="__('Rails-specific knobs appear when the repository inspector detects a Rails app.')"
        />
        <div class="{{ $panelBody }}">
            <p class="rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-2 text-xs text-brand-moss">
                <span class="font-semibold text-brand-ink">{{ __('No Ruby-specific knobs detected.') }}</span>
                {{ __('Once a Rails (or other Ruby) framework is detected from the repository, framework-specific settings will appear here.') }}
            </p>
        </div>
    </section>
@endif

<div class="border-t border-brand-ink/10 bg-brand-sand/25 px-3 py-2.5 sm:px-4">
    <x-cli-snippet :commands="[
        ['label' => __('Set Ruby version'), 'command' => 'dply sites:runtime:set '.$site->slug.' --runtime=ruby --runtime-version=3.3'],
        ['label' => __('Set start command'), 'command' => 'dply sites:runtime:set '.$site->slug.' --start=\'bundle exec puma -C config/puma.rb\' --port=3000'],
    ]" />
</div>
