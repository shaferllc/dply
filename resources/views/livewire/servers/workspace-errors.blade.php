<x-server-workspace-layout
    :server="$server"
    active="errors"
    :title="__('Errors')"
    :description="__('Every failure on this server and the sites it hosts — newest first. Dismiss what you’ve handled; retry where supported.')"
    hide-hero
>
    <section class="dply-card min-w-0 overflow-hidden p-0">
        {{-- Dense head, matching the rest of the workspace. --}}
        <x-workspace-panel-head
            dense
            icon="heroicon-o-exclamation-triangle"
            :title="__('Errors')"
            :note="__('Failures on this server and its sites — newest first. Dismiss what you’ve handled; retry where supported.')"
            class="border-b border-brand-ink/10"
        />

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

        {{-- One skeleton per tab: Stream arrives as filter chips + error rows,
             Notifications as a routed-channel list and the add form, so a single
             shared stub resized on arrival. --}}
        @php $bar = 'animate-pulse rounded bg-brand-ink/10'; @endphp
        @foreach (['stream', 'notifications'] as $skeletonTab)
            <div class="hidden" wire:loading.class.remove="hidden" wire:target="setErrorsWorkspaceTab('{{ $skeletonTab }}')" aria-busy="true" aria-live="polite">
                <span class="sr-only">{{ __('Loading section…') }}</span>
                @if ($skeletonTab === 'stream')
                    <div class="flex flex-wrap items-center gap-1.5 border-b border-brand-ink/10 px-4 py-2 sm:px-5" aria-hidden="true">
                        @foreach ([16, 20, 14, 18, 16] as $chip)
                            <span class="h-6 rounded-full {{ $bar }}" style="width: {{ $chip * 4 }}px;"></span>
                        @endforeach
                    </div>
                    <div class="divide-y divide-brand-ink/10" aria-hidden="true">
                        @foreach (range(1, 5) as $row)
                            <div class="flex items-start gap-3 px-4 py-2.5 sm:px-5">
                                <span class="h-7 w-7 shrink-0 rounded-full {{ $bar }}"></span>
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <div class="h-2.5 w-48 max-w-full {{ $bar }}"></div>
                                    <div class="h-2 w-2/3 {{ $bar }}"></div>
                                </div>
                                <span class="h-2 w-16 shrink-0 {{ $bar }}"></span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-2 sm:px-4" aria-hidden="true">
                        <span class="h-4 w-4 shrink-0 {{ $bar }}"></span>
                        <span class="h-3.5 w-28 shrink-0 {{ $bar }}"></span>
                        <span class="h-4 w-20 shrink-0 rounded-full {{ $bar }}"></span>
                        <span class="h-4 w-px shrink-0 bg-brand-ink/10"></span>
                        <span class="h-2.5 min-w-0 flex-1 {{ $bar }}"></span>
                        <span class="h-6 w-32 shrink-0 rounded-lg {{ $bar }}"></span>
                    </div>
                    <div class="space-y-2 px-4 py-3.5 sm:px-5" aria-hidden="true">
                        @foreach (range(1, 2) as $channel)
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-2.5">
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <div class="h-2.5 w-36 max-w-full {{ $bar }}"></div>
                                    <div class="h-2 w-16 {{ $bar }}"></div>
                                </div>
                                <span class="h-5 w-24 shrink-0 rounded-full {{ $bar }}"></span>
                            </div>
                        @endforeach
                        <div class="grid gap-3 pt-1 sm:grid-cols-2">
                            @foreach (range(1, 2) as $field)
                                <div class="space-y-1.5">
                                    <div class="h-2.5 w-16 {{ $bar }}"></div>
                                    <div class="h-9 w-full rounded-lg {{ $bar }}"></div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        @endforeach

        <div wire:loading.class="hidden" wire:target="setErrorsWorkspaceTab">
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
