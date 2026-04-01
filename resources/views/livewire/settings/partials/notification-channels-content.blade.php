<x-livewire-validation-errors />

@if (empty($useOrgShell))
<nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
    <ol class="flex flex-wrap items-center gap-2">
        @foreach ($breadcrumbs as $i => $crumb)
            @if ($i > 0)
                <li class="text-brand-mist" aria-hidden="true">/</li>
            @endif
            <li>
                @if (! empty($crumb['url']))
                    <a href="{{ $crumb['url'] }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ $crumb['label'] }}</a>
                @else
                    <span class="text-brand-ink font-medium">{{ $crumb['label'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>

@if (! empty($backUrl))
    <p class="mb-4">
        <a href="{{ $backUrl }}" wire:navigate class="text-sm font-medium text-brand-sage hover:underline">{{ $backLabel ?? __('Back') }}</a>
    </p>
@endif
@endif

<x-page-header :title="$pageTitle" :description="$intro" flush>
    @if (! empty($showBulkAssign ?? false))
        <x-slot name="actions">
            <a
                href="{{ route('profile.notification-channels.bulk-assign') }}"
                wire:navigate
                class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
            >{{ __('Bulk assign notifications') }}</a>
        </x-slot>
    @endif
</x-page-header>

@if ($flash_success)
    <x-alert tone="success" class="mb-6">{{ $flash_success }}</x-alert>
@endif
@if ($flash_error)
    <x-alert tone="error" class="mb-6">{{ $flash_error }}</x-alert>
@endif

<div class="space-y-10">
    @if (($currentOrganization ?? null) || ($teamChannelGroups ?? collect())->isNotEmpty())
        <x-section-card>
            <x-slot name="header">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Available beyond your personal channels') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Organization-owned and team-owned channels can be assigned too. Manage them from their own settings pages.') }}</p>
            </x-slot>
            <div class="space-y-6">
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
                                <a href="{{ route('organizations.notification-channels', $currentOrganization) }}" wire:navigate class="rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/50">
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
                                    <a href="{{ route('teams.notification-channels', [$entry['team']->organization, $entry['team']]) }}" wire:navigate class="rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/50">
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
        </x-section-card>
    @endif

    <x-section-card>
        <x-slot name="header">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Create notification channel') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Connect chat, email, Pushover, webhooks, or mobile tokens. Credentials are stored encrypted.') }}</p>
        </x-slot>
        @if ($canManage && count($types) === 0)
            <x-empty-state
                :title="__('No notification channel types are enabled.')"
                :description="__('Add types in configuration (DPLY_NOTIFICATION_CHANNEL_TYPES) when ready.')"
                class="m-6 sm:m-8"
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
                class="m-6 sm:m-8"
                :dashed="false"
            />
        @endif
    </x-section-card>

    <x-section-card>
        <x-slot name="header">
            <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('My channels') }}</h2>
            <x-text-input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="{{ __('Search…') }}"
                class="w-full max-w-xs"
            />
            </div>
        </x-slot>
        <x-table-shell class="border-0 rounded-none">
            <table class="min-w-full divide-y divide-brand-ink/10">
                <thead>
                    <tr class="bg-brand-sand/40 text-left text-xs font-semibold uppercase tracking-wider text-brand-moss">
                        <th scope="col" class="px-6 py-3">{{ __('Label') }}</th>
                        <th scope="col" class="px-6 py-3">{{ __('Type') }}</th>
                        <th scope="col" class="px-6 py-3 text-right tabular-nums">{{ __('Usages') }}</th>
                        <th scope="col" class="px-6 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/10 text-sm">
                    @forelse ($channels as $channel)
                        <tr wire:key="nc-{{ $channel->id }}">
                            @if ($editing_id === $channel->id)
                                <td colspan="4" class="px-6 py-4 bg-brand-sand/20">
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
                                <td class="px-6 py-3 font-medium text-brand-ink">{{ $channel->label }}</td>
                                <td class="px-6 py-3 text-brand-moss">{{ \App\Models\NotificationChannel::labelForType($channel->type) }}</td>
                                <td class="px-6 py-3 text-right tabular-nums text-brand-moss">{{ $channel->subscriptions_count }}</td>
                                <td class="px-6 py-3 text-right">
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
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-10 text-center text-sm text-brand-moss">{{ __('No channels yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-table-shell>
    </x-section-card>
</div>
