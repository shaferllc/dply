<div class="contents">
    <div class="border-b border-amber-600/40 bg-amber-500 text-amber-950">
        <div class="mx-auto max-w-7xl px-4 py-2.5 sm:px-6 lg:px-8">
            <p class="text-sm font-bold tracking-wide">{{ __('PRODUCTION DATA') }}</p>
            <p class="text-sm text-amber-950/90">{{ __('Connect this local app to a live control plane. Local-only feature.') }}</p>
        </div>
    </div>

    <div class="mx-auto max-w-2xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
        <x-breadcrumb-trail :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Production data'), 'icon' => 'exclamation-triangle'],
        ]" />

        <div class="dply-card space-y-5 p-6">
            <div>
                <h1 class="text-xl font-semibold text-brand-ink">{{ __('Connect production') }}</h1>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('Approve device-flow on the remote host. Prefer minimal scopes: sites.read, sites.deploy, sites.write, servers.read, projects.read, edge.read, account.read.') }}
                </p>
            </div>

            @if ($errorMessage)
                <x-alert tone="danger">{{ $errorMessage }}</x-alert>
            @endif

            @if ($status === 'polling')
                <div class="space-y-4" wire:poll.{{ $pollInterval }}s="pollDeviceFlow">
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 p-4">
                        <p class="text-sm font-medium text-brand-ink">{{ __('Enter this code on production') }}</p>
                        <p class="mt-2 font-mono text-2xl font-bold tracking-widest text-brand-ink">{{ $userCode }}</p>
                        @if ($verificationUriComplete)
                            <a
                                href="{{ $verificationUriComplete }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="mt-3 inline-flex text-sm font-semibold text-brand-sage hover:underline"
                            >
                                {{ __('Open approval page') }} →
                            </a>
                        @endif
                    </div>
                    <p class="text-sm text-brand-moss">{{ __('Waiting for approval…') }}</p>
                    <x-secondary-button type="button" wire:click="cancelDeviceFlow">{{ __('Cancel') }}</x-secondary-button>
                </div>
            @else
                <div>
                    <x-input-label for="live_base_url" :value="__('Production API origin')" />
                    <x-text-input
                        id="live_base_url"
                        type="url"
                        wire:model="baseUrl"
                        class="mt-1 w-full font-mono text-sm"
                        placeholder="{{ $defaultBaseUrl }}"
                    />
                    @error('baseUrl')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Defaults from DPLY_LIVE_API_BASE_URL. Must be reachable from this machine.') }}</p>
                </div>

                <x-primary-button type="button" wire:click="startDeviceFlow" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="startDeviceFlow">{{ __('Start device login') }}</span>
                    <span wire:loading wire:target="startDeviceFlow">{{ __('Starting…') }}</span>
                </x-primary-button>
            @endif
        </div>
    </div>
</div>
