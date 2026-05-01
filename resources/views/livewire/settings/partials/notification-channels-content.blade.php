<x-livewire-validation-errors />

@if (! empty($breadcrumbs))
    <x-breadcrumb-trail :items="$breadcrumbs" />
@endif

@if (! empty($backUrl))
    <p class="mb-4">
        <a href="{{ $backUrl }}" wire:navigate class="text-sm font-medium text-brand-sage hover:underline">{{ $backLabel ?? __('Back') }}</a>
    </p>
@endif

<div class="space-y-8">
    <div class="dply-card overflow-hidden">
        <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
            <div class="lg:col-span-4">
                <h2 class="text-lg font-semibold text-brand-ink">{{ $pageTitle }}</h2>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ $intro }}</p>
            </div>
            <div class="lg:col-span-8 flex flex-wrap items-start justify-end gap-3">
                <x-outline-link href="{{ route('docs.index') }}" wire:navigate>
                    <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Documentation') }}
                </x-outline-link>
                @if (! empty($showBulkAssign ?? false))
                    <a
                        href="{{ route('profile.notification-channels.bulk-assign') }}"
                        wire:navigate
                        class="inline-flex items-center justify-center rounded-xl border border-transparent bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md hover:bg-brand-forest transition-colors"
                    >{{ __('Bulk assign notifications') }}</a>
                @endif
            </div>
        </div>
    </div>

    @if (empty($useOrgShell) && (($currentOrganization ?? null) || ($teamChannelGroups ?? collect())->isNotEmpty()))
        <div class="dply-card overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Available beyond your personal channels') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Organization-owned and team-owned channels can be assigned too. Manage them from their own settings pages.') }}</p>
                </div>
                <div class="lg:col-span-8 space-y-6 min-w-0">
                    @if (($currentOrganization ?? null) && isset($organizationChannels))
                        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Current organization') }}: {{ $currentOrganization->name }}</h3>
                                    <p class="mt-1 text-sm text-brand-moss">
                                        {{ $organizationChannels->isEmpty() ? __('No organization channels yet.') : trans_choice('{1} :count organization channel|[2,*] :count organization channels', $organizationChannels->count(), ['count' => $organizationChannels->count()]) }}
                                    </p>
                                </div>
                                @can('viewNotificationChannels', $currentOrganization)
                                    <a href="{{ route('organizations.notification-channels', $currentOrganization) }}" wire:navigate class="rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/50 shrink-0">
                                        {{ __('Open organization channels') }}
                                    </a>
                                @endcan
                            </div>

                            @if ($organizationChannels->isNotEmpty())
                                <div class="mt-4 space-y-2">
                                    @foreach ($organizationChannels as $channel)
                                        <div class="flex items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 text-sm">
                                            <div>
                                                <p class="font-medium text-brand-ink">{{ $channel->label }}</p>
                                                <p class="text-brand-moss">{{ \App\Models\NotificationChannel::labelForType($channel->type) }}</p>
                                            </div>
                                            <p class="shrink-0 text-xs text-brand-mist">{{ trans_choice('{1} :count use|[2,*] :count uses', $channel->subscriptions_count, ['count' => $channel->subscriptions_count]) }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    @if (($teamChannelGroups ?? collect())->isNotEmpty())
                        <div class="space-y-4">
                            @foreach ($teamChannelGroups as $entry)
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-4">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <h3 class="text-sm font-semibold text-brand-ink">{{ __('Team') }}: {{ $entry['team']->name }}</h3>
                                            <p class="mt-1 text-sm text-brand-moss">{{ trans_choice('{1} :count team channel|[2,*] :count team channels', $entry['channels']->count(), ['count' => $entry['channels']->count()]) }}</p>
                                        </div>
                                        <a href="{{ route('teams.notification-channels', [$entry['team']->organization, $entry['team']]) }}" wire:navigate class="rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/50 shrink-0">
                                            {{ __('Open team channels') }}
                                        </a>
                                    </div>

                                    <div class="mt-4 space-y-2">
                                        @foreach ($entry['channels'] as $channel)
                                            <div class="flex items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 text-sm">
                                                <div>
                                                    <p class="font-medium text-brand-ink">{{ $channel->label }}</p>
                                                    <p class="text-brand-moss">{{ \App\Models\NotificationChannel::labelForType($channel->type) }}</p>
                                                </div>
                                                <p class="shrink-0 text-xs text-brand-mist">{{ trans_choice('{1} :count use|[2,*] :count uses', $channel->subscriptions_count, ['count' => $channel->subscriptions_count]) }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <div class="dply-card overflow-hidden">
        <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
            <div class="lg:col-span-4">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Create notification channel') }}</h2>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Connect chat, email, Pushover, webhooks, or mobile tokens. Credentials are stored encrypted.') }}</p>
            </div>
            <div class="lg:col-span-8 min-w-0">
                @if ($canManage && count($types) === 0)
                    <x-empty-state
                        :title="__('No notification channel types are enabled.')"
                        :description="__('Add types in configuration (DPLY_NOTIFICATION_CHANNEL_TYPES) when ready.')"
                        class="py-4"
                    />
                @elseif ($canManage)
                    <form wire:submit="createChannel" class="space-y-5">
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="new_type" :value="__('Type')" />
                                <x-select id="new_type" wire:model.live="new_type">
                                    @foreach ($types as $t)
                                        <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                                    @endforeach
                                </x-select>
                                @error('new_type')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <div>
                                <x-input-label for="new_label" :value="__('Label')" />
                                <x-text-input id="new_label" type="text" wire:model="new_label" placeholder="{{ __('e.g. #alerts') }}" required />
                                @error('new_label')
                                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'new_', 'type' => $new_type])

                        <div class="flex justify-end">
                            <x-primary-button type="submit">{{ __('Create') }}</x-primary-button>
                        </div>
                    </form>
                @else
                    <x-empty-state
                        :title="__('You can view channels here.')"
                        :description="__('Ask an admin to add or change destinations.')"
                        class="py-4"
                        :dashed="false"
                    />
                @endif
            </div>
        </div>
    </div>

    <div class="dply-card overflow-hidden">
        <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
            <div class="lg:col-span-4">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('My channels') }}</h2>
                <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Search, edit, test, or remove destinations.') }}</p>
            </div>
            <div class="lg:col-span-8 space-y-4 min-w-0">
                <div class="flex justify-end">
                    <label for="nc_my_channels_search" class="sr-only">{{ __('Search') }}</label>
                    <x-text-input
                        id="nc_my_channels_search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search…') }}"
                        class="w-full max-w-xs"
                        autocomplete="off"
                    />
                </div>
                @php
                    $hasChannelSearch = trim($search ?? '') !== '';
                @endphp
                @if (! $hasChannelSearch && $channels->isEmpty())
                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-6 py-12 text-center text-sm text-brand-moss">
                        {{ __('No channels yet.') }}
                    </div>
                @elseif ($hasChannelSearch && $channels->isEmpty())
                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-6 py-12 text-center text-sm text-brand-moss">
                        {{ __('No channels match your search.') }}
                    </div>
                @else
                    <div class="overflow-x-auto rounded-xl border border-brand-mist">
                        <table class="min-w-full divide-y divide-brand-mist/80 text-sm">
                            <thead>
                                <tr class="bg-brand-sand/20 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <th scope="col" class="px-4 py-3">{{ __('Label') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Type') }}</th>
                                    <th scope="col" class="px-4 py-3 text-right tabular-nums">{{ __('Usages') }}</th>
                                    <th scope="col" class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-mist/80 bg-white">
                                @foreach ($channels as $channel)
                                    <tr wire:key="nc-{{ $channel->id }}">
                                        @if ($editing_id === $channel->id)
                                            <td colspan="4" class="bg-brand-sand/20 px-4 py-4">
                                                <form wire:submit="saveEdit" class="space-y-4">
                                                    <div class="grid gap-4 sm:grid-cols-2">
                                                        <div>
                                                            <x-input-label for="edit_type" :value="__('Type')" />
                                                            <x-select id="edit_type" wire:model.live="edit_type">
                                                                @foreach ($typesForEdit as $t)
                                                                    <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                                                                @endforeach
                                                            </x-select>
                                                            @error('edit_type')
                                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                        <div>
                                                            <x-input-label for="edit_label" :value="__('Label')" />
                                                            <x-text-input id="edit_label" type="text" wire:model="edit_label" />
                                                            @error('edit_label')
                                                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                    </div>
                                                    @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'edit_', 'type' => $edit_type])
                                                    <div class="flex flex-wrap gap-2">
                                                        <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                                                        <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                                                    </div>
                                                </form>
                                            </td>
                                        @else
                                            <td class="px-4 py-3 font-medium text-brand-ink">{{ $channel->label }}</td>
                                            <td class="px-4 py-3 text-brand-moss">{{ \App\Models\NotificationChannel::labelForType($channel->type) }}</td>
                                            <td class="px-4 py-3 text-right tabular-nums text-brand-moss">{{ $channel->subscriptions_count }}</td>
                                            <td class="px-4 py-3 text-right">
                                                <div class="flex flex-wrap justify-end gap-2">
                                                    @if ($canManage)
                                                        <button
                                                            type="button"
                                                            wire:click="sendTest({{ $channel->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="sendTest"
                                                            class="inline-flex items-center gap-1.5 text-sm font-medium text-brand-sage hover:underline disabled:opacity-50"
                                                        >
                                                            <span wire:loading.remove wire:target="sendTest">{{ __('Send test') }}</span>
                                                            <span wire:loading wire:target="sendTest" class="inline-flex items-center gap-1.5">
                                                                <x-spinner variant="forest" size="sm" />
                                                                {{ __('Sending…') }}
                                                            </span>
                                                        </button>
                                                        <button type="button" wire:click="startEdit('{{ $channel->id }}')" class="text-sm font-medium text-brand-ink hover:underline">{{ __('Edit') }}</button>
                                                        <button
                                                            type="button"
                                                            wire:click="openConfirmActionModal('deleteChannel', ['{{ $channel->id }}'], @js(__('Delete notification channel')), @js(__('Remove this channel?')), @js(__('Delete')), true)"
                                                            class="text-sm font-medium text-red-600 hover:underline"
                                                        >{{ __('Delete') }}</button>
                                                    @endif
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
