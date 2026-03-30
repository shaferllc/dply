<div>
    <x-livewire-validation-errors />

    <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('profile.edit') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Profile') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('profile.notification-channels') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Notification channels') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('Bulk assign notifications') }}</li>
        </ol>
    </nav>

    <header class="mb-8">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Bulk assign notifications') }}</h1>
        <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
            {{ __('Link channels you can manage to events, then choose servers and sites in your current organization.') }}
        </p>
    </header>

    @if ($flash_success)
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">{{ $flash_success }}</div>
    @endif

    @if (! $currentOrganization)
        <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950">
            {{ __('Select a current organization from the header to load servers and sites as assignment targets.') }}
        </div>
    @else
        <p class="mb-6 text-sm text-brand-moss">
            {{ __('Organization:') }} <span class="font-medium text-brand-ink">{{ $currentOrganization->name }}</span>
        </p>
    @endif

    <div class="space-y-8">
        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8 flex flex-wrap justify-between gap-3 items-start">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Notification channels') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Select which channels to attach to the chosen events and targets.') }}</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="selectAllChannels" class="text-sm font-medium text-brand-sage hover:underline">{{ __('Select all') }}</button>
                    <button type="button" wire:click="deselectAllChannels" class="text-sm font-medium text-brand-moss hover:underline">{{ __('Deselect all') }}</button>
                </div>
            </div>
            <div class="px-6 py-4 sm:px-8 space-y-2 max-h-64 overflow-y-auto">
                @forelse ($assignableChannels as $ch)
                    <label class="flex items-center gap-3 text-sm cursor-pointer">
                        <input type="checkbox" wire:model.live="selected_channel_ids" value="{{ $ch->id }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                        <span><span class="font-medium text-brand-ink">[{{ \App\Models\NotificationChannel::labelForType($ch->type) }}]</span> {{ $ch->label }}</span>
                    </label>
                @empty
                    <p class="text-sm text-brand-moss">{{ __('No channels available. Create notification channels under your profile, organization, or team first.') }}</p>
                @endforelse
            </div>
            @error('selected_channel_ids')
                <p class="px-6 sm:px-8 pb-2 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </section>

        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8 flex flex-wrap justify-between gap-3 items-start">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Notification type') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Choose which events should trigger notifications on the selected channels.') }}</p>
                </div>
                <div class="flex gap-2">
                    <button type="button" wire:click="selectAllEvents" class="text-sm font-medium text-brand-sage hover:underline">{{ __('Select all') }}</button>
                    <button type="button" wire:click="deselectAllEvents" class="text-sm font-medium text-brand-moss hover:underline">{{ __('Deselect all') }}</button>
                </div>
            </div>
            <div class="px-6 py-4 sm:px-8 space-y-6">
                @foreach ($eventCatalog as $catKey => $cat)
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wider text-brand-mist mb-2">{{ $cat['label'] }}</p>
                        <ul class="space-y-2">
                            @foreach ($cat['events'] as $eventKey => $eventLabel)
                                <li>
                                    <label class="flex items-center gap-3 text-sm cursor-pointer">
                                        <input type="checkbox" wire:model.live="selected_event_keys" value="{{ $eventKey }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                                        <span>{{ $eventLabel }}</span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
            @error('selected_event_keys')
                <p class="px-6 sm:px-8 pb-2 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </section>

        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8 flex flex-wrap justify-between gap-3 items-start">
                <div>
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Select targets') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">{{ __('Servers apply to server events; sites apply to site and backup events.') }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" wire:click="selectAllServers" class="text-sm font-medium text-brand-sage hover:underline">{{ __('All servers') }}</button>
                    <button type="button" wire:click="deselectAllServers" class="text-sm font-medium text-brand-moss hover:underline">{{ __('No servers') }}</button>
                    <button type="button" wire:click="selectAllSites" class="text-sm font-medium text-brand-sage hover:underline">{{ __('All sites') }}</button>
                    <button type="button" wire:click="deselectAllSites" class="text-sm font-medium text-brand-moss hover:underline">{{ __('No sites') }}</button>
                </div>
            </div>
            <div class="px-6 py-4 sm:px-8">
                @if ($selected_channel_ids === [] || $selected_event_keys === [])
                    <div class="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                        {{ __('Select at least one channel and a notification type first.') }}
                    </div>
                @else
                    <div class="grid gap-8 md:grid-cols-2">
                        <div>
                            <p class="text-sm font-medium text-brand-ink mb-2">{{ __('Servers') }}</p>
                            <div class="space-y-2 max-h-48 overflow-y-auto">
                                @forelse ($servers as $server)
                                    <label class="flex items-center gap-3 text-sm cursor-pointer">
                                        <input type="checkbox" wire:model.live="selected_server_ids" value="{{ $server->id }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                                        <span>{{ $server->name }}</span>
                                    </label>
                                @empty
                                    <p class="text-sm text-brand-moss">{{ __('No servers in this organization.') }}</p>
                                @endforelse
                            </div>
                            @error('selected_server_ids')
                                <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <p class="text-sm font-medium text-brand-ink mb-2">{{ __('Sites') }}</p>
                            <div class="space-y-2 max-h-48 overflow-y-auto">
                                @forelse ($sites as $site)
                                    <label class="flex items-center gap-3 text-sm cursor-pointer">
                                        <input type="checkbox" wire:model.live="selected_site_ids" value="{{ $site->id }}" class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage">
                                        <span>{{ $site->name }}</span>
                                    </label>
                                @empty
                                    <p class="text-sm text-brand-moss">{{ __('No sites in this organization.') }}</p>
                                @endforelse
                            </div>
                            @error('selected_site_ids')
                                <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <div class="flex justify-end">
            <button
                type="button"
                wire:click="assign"
                wire:loading.attr="disabled"
                @disabled(! $this->canSubmitAssign())
                class="inline-flex items-center justify-center px-5 py-2.5 rounded-xl bg-brand-ink font-semibold text-sm text-brand-cream shadow-md shadow-brand-ink/15 hover:bg-brand-forest focus:outline-none focus:ring-2 focus:ring-brand-sage focus:ring-offset-2 disabled:opacity-40 disabled:cursor-not-allowed"
            >{{ __('Assign notifications') }}</button>
        </div>
    </div>
</div>
