<x-server-workspace-layout
    :server="$server"
    active="errors"
    :title="__('Errors')"
    :description="__('Every failure on this server and the sites it hosts — newest first. Dismiss what you’ve handled; retry where supported.')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0">
        <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <h2 class="text-lg font-semibold tracking-tight text-brand-ink">{{ __('Errors') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Failures on this server and its sites — newest first. Dismiss what you’ve handled; retry where supported.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Errors workspace sections')" scroll bare class="!mb-0">
                <x-server-workspace-tab
                    id="errors-tab-stream"
                    icon="heroicon-o-exclamation-triangle"
                    :active="$errorsTab === 'stream'"
                    wire:click="setErrorsWorkspaceTab('stream')"
                >
                    {{ __('Stream') }}
                </x-server-workspace-tab>
                <x-server-workspace-tab
                    id="errors-tab-notifications"
                    icon="heroicon-o-bell"
                    :active="$errorsTab === 'notifications'"
                    wire:click="setErrorsWorkspaceTab('notifications')"
                >
                    {{ __('Notifications') }}
                </x-server-workspace-tab>
            </x-server-workspace-tablist>
        </div>

        <div wire:loading.block wire:target="setErrorsWorkspaceTab" class="px-5 py-6 sm:px-6" aria-busy="true">
            <span class="sr-only">{{ __('Loading…') }}</span>
            <div class="space-y-3" aria-hidden="true">
                <div class="flex flex-wrap gap-1.5">
                    @foreach (range(1, 5) as $chip)
                        <span class="inline-flex h-7 w-16 animate-pulse rounded-full bg-brand-ink/10"></span>
                    @endforeach
                </div>
                @foreach (range(1, 4) as $row)
                    <div class="flex items-start gap-3 border-t border-brand-ink/10 pt-3">
                        <span class="mt-0.5 h-7 w-7 shrink-0 animate-pulse rounded-full bg-brand-ink/10"></span>
                        <div class="min-w-0 flex-1 space-y-2">
                            <div class="h-3.5 w-48 max-w-full animate-pulse rounded bg-brand-ink/10"></div>
                            <div class="h-2.5 w-32 animate-pulse rounded bg-brand-ink/10"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div wire:loading.remove wire:target="setErrorsWorkspaceTab">
            @if ($errorsTab === 'stream')
                @include('livewire.partials.error-stream', ['errorStreamNested' => true])
            @endif

            @if ($errorsTab === 'notifications')
                @include('livewire.servers.partials.errors.notifications-tab')
            @endif
        </div>
    </section>

    @include('livewire.partials.confirm-action-modal')

    @include('livewire.partials.create-notification-channel-modal')

    @include('livewire.partials.error-logs-drawer')
</x-server-workspace-layout>
