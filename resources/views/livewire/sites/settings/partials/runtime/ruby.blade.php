@php
    $detectedFramework = strtolower((string) ($site->resolvedRuntimeAppDetection()['framework'] ?? ''));
    $isRailsLike = $detectedFramework === 'rails' || $site->shouldShowRailsRuntimeSettings();
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-command-line class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Ruby') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Ruby runtime') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                @if ($isRailsLike)
                    {{ __('Ruby/Rails-specific runtime settings. Stored on the site for deploy scripts and operator reference.') }}
                @else
                    {{ __('Ruby runtime settings. Rails-specific knobs appear when the repository inspector detects a Rails app.') }}
                @endif
            </p>
        </div>
    </div>

    <div class="px-6 py-6 sm:px-7 space-y-6">
    @if ($isRailsLike)
        <form wire:submit="saveRuntimePreferences" class="space-y-6">
            <div class="max-w-md">
                <x-input-label for="rails_env" :value="__('RAILS_ENV')" />
                <x-text-input id="rails_env" wire:model="rails_env" class="mt-1 block w-full font-mono text-sm" placeholder="production" />
                <p class="mt-1 text-xs text-brand-moss">{{ __('Stored on the site for deploy scripts and operator reference. Align with your Puma/Thruster and systemd configuration. The same value also appears under Deploy → Rollout and web server.') }}</p>
                <x-input-error :messages="$errors->get('rails_env')" class="mt-1" />
            </div>

            <div class="border-t border-brand-ink/10 pt-6">
                <x-primary-button type="submit">{{ __('Save Ruby runtime settings') }}</x-primary-button>
            </div>
        </form>
    @else
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/30 p-4 text-sm text-brand-moss">
            <p class="font-medium text-brand-ink">{{ __('No Ruby-specific knobs detected') }}</p>
            <p class="mt-1">{{ __('Once a Rails (or other Ruby) framework is detected from the repository, framework-specific settings will appear here.') }}</p>
        </div>
    @endif
    </div>
</section>

<x-cli-snippet :commands="[
    ['label' => __('Set Ruby version'), 'command' => 'dply:site:set-runtime '.$site->slug.' --runtime=ruby --runtime-version=3.3'],
    ['label' => __('Set start command'), 'command' => 'dply:site:set-runtime '.$site->slug.' --start=\'bundle exec puma -C config/puma.rb\' --port=3000'],
]" />
