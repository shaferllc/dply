<div class="contents">
    <x-workspace-nav />

    <x-cloud-index-page
        :rows="$rows"
        :totals="$totals"
        :has-apps-in-scope="$hasAppsInScope"
        :has-any-backend-credential="$hasAnyBackendCredential"
        :cloud-enabled="true"
        :api-ready="true"
        :filter="$filter"
        :show-filters="true"
        :show-create-action="true"
        :show-databases-action="true"
        empty-state="local"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Cloud apps'), 'icon' => 'cloud'],
        ]"
    >
        {{-- Credential nudge for orgs that already have apps but lost/never had a backend account.
             Empty orgs get the same CTAs inside the empty dashboard instead. --}}
        @if ($hasAppsInScope && ! $hasAnyBackendCredential)
            <x-slot:alert>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-3">
                        <div class="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-gold/20 text-brand-rust">
                            <x-heroicon-o-link class="h-4 w-4" aria-hidden="true" />
                        </div>
                        <div class="space-y-1">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Connect a cloud account to deploy') }}</p>
                            <p class="text-sm text-brand-moss">{{ __('dply needs a DigitalOcean or AWS account to run your apps on. Connect once and we handle the rest.') }}</p>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-2 text-xs">
                        <a href="{{ route('credentials.index', ['provider' => 'digitalocean']) }}" wire:navigate class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 font-semibold text-brand-cream hover:bg-brand-ink/90">
                            {{ __('Connect DigitalOcean') }}
                        </a>
                        <a href="{{ route('credentials.index', ['provider' => 'aws_app_runner']) }}" wire:navigate class="font-medium text-brand-moss hover:text-brand-ink">{{ __('Use AWS') }}</a>
                    </div>
                </div>
            </x-slot:alert>
        @endif
    </x-cloud-index-page>
</div>
