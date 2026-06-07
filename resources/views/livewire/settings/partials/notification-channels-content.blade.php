@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $channelTotal = $channels->count();
    $orgChannelCount = isset($organizationChannels) ? $organizationChannels->count() : 0;
    $teamChannelCount = ($teamChannelGroups ?? collect())->sum(fn ($e) => $e['channels']->count());
    $hasChannelSearch = trim($search ?? '') !== '';
@endphp

<x-livewire-validation-errors />

@if (! empty($breadcrumbs))
    <x-breadcrumb-trail :items="$breadcrumbs" />
@endif

@if (! empty($backUrl))
    <p class="mb-4">
        <a href="{{ $backUrl }}" wire:navigate class="inline-flex items-center gap-1 text-sm font-medium text-brand-sage hover:text-brand-ink">
            <x-heroicon-m-chevron-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            {{ $backLabel ?? __('Back') }}
        </a>
    </p>
@endif

{{-- Hero card: positioning + at-a-glance counts. --}}
<section class="dply-card overflow-hidden">
    <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
        <div class="lg:col-span-7">
            <div class="flex items-start gap-3">
                <x-icon-badge size="md">
                    <x-heroicon-o-bell-alert class="h-6 w-6" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Routing') }}</p>
                    <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ $pageTitle }}</h2>
                    <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">{{ $intro }}</p>
                </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-2">
                <x-outline-link href="{{ route('docs.index') }}" wire:navigate>
                    <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Documentation') }}
                </x-outline-link>
                @if (! empty($showBulkAssign ?? false))
                    <a
                        href="{{ route('profile.notification-channels.bulk-assign') }}"
                        wire:navigate
                        class="inline-flex items-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Bulk assign') }}
                    </a>
                @endif
                @if ($canManage && count($types) > 0)
                    <button
                        type="button"
                        wire:click="openCreateChannelModal"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add channel') }}
                    </button>
                @endif
            </div>
        </div>
        <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Personal') }}</dt>
                <dd class="mt-1 flex items-baseline gap-1.5">
                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $channelTotal }}</span>
                    <span class="text-[11px] text-brand-moss">{{ trans_choice('channel|channels', $channelTotal) }}</span>
                </dd>
                <p class="mt-1 text-[11px] text-brand-mist">{{ __('You own') }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Organization') }}</dt>
                <dd class="mt-1 flex items-baseline gap-1.5">
                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $orgChannelCount }}</span>
                    <span class="text-[11px] text-brand-moss">{{ trans_choice('available|available', $orgChannelCount) }}</span>
                </dd>
                <p class="mt-1 text-[11px] text-brand-mist">{{ ($currentOrganization ?? null) ? $currentOrganization->name : __('No current org') }}</p>
            </div>
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Teams') }}</dt>
                <dd class="mt-1 flex items-baseline gap-1.5">
                    <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $teamChannelCount }}</span>
                    <span class="text-[11px] text-brand-moss">{{ trans_choice('available|available', $teamChannelCount) }}</span>
                </dd>
                <p class="mt-1 text-[11px] text-brand-mist">{{ trans_choice(':n team|:n teams', ($teamChannelGroups ?? collect())->count(), ['n' => ($teamChannelGroups ?? collect())->count()]) }}</p>
            </div>
        </dl>
    </div>
</section>

<div class="mt-6 space-y-6">

    {{-- "Available beyond your personal channels" — shown only on the
         personal /settings/notification-channels page, where the user
         can also see org + team-owned channels that target them. --}}
    @if (empty($useOrgShell) && (($currentOrganization ?? null) || ($teamChannelGroups ?? collect())->isNotEmpty()))
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-user-group class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Inherited') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Available beyond your personal channels') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Organization- and team-owned channels can be assigned too. Manage them from their own settings pages.') }}</p>
                </div>
            </div>

            <div class="space-y-5 p-6 sm:p-7">
                @if (($currentOrganization ?? null) && isset($organizationChannels))
                    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/30 px-4 py-2.5">
                            <div class="min-w-0">
                                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Organization') }}</p>
                                <p class="truncate text-sm font-semibold text-brand-ink">{{ $currentOrganization->name }}</p>
                            </div>
                            @can('viewNotificationChannels', $currentOrganization)
                                <a href="{{ route('organizations.notification-channels', $currentOrganization) }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                    {{ __('Manage') }} →
                                </a>
                            @endcan
                        </div>
                        @if ($organizationChannels->isEmpty())
                            <p class="px-4 py-4 text-sm text-brand-mist">{{ __('No organization channels yet.') }}</p>
                        @else
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($organizationChannels as $channel)
                                    <li class="flex items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-brand-sand/15">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $channel->label }}</p>
                                            <p class="text-[11px] text-brand-moss">{{ \App\Models\NotificationChannel::labelForType($channel->type) }}</p>
                                        </div>
                                        <span class="shrink-0 rounded-md bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ trans_choice(':n use|:n uses', $channel->subscriptions_count, ['n' => $channel->subscriptions_count]) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @endif

                @if (($teamChannelGroups ?? collect())->isNotEmpty())
                    @foreach ($teamChannelGroups as $entry)
                        <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white">
                            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/30 px-4 py-2.5">
                                <div class="min-w-0">
                                    <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-moss">{{ __('Team') }}</p>
                                    <p class="truncate text-sm font-semibold text-brand-ink">{{ $entry['team']->name }}</p>
                                </div>
                                <a href="{{ route('teams.notification-channels', [$entry['team']->organization, $entry['team']]) }}" wire:navigate class="shrink-0 text-xs font-semibold text-brand-sage hover:text-brand-ink">
                                    {{ __('Manage') }} →
                                </a>
                            </div>
                            <ul class="divide-y divide-brand-ink/10">
                                @foreach ($entry['channels'] as $channel)
                                    <li class="flex items-center justify-between gap-3 px-4 py-3 transition-colors hover:bg-brand-sand/15">
                                        <div class="min-w-0">
                                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $channel->label }}</p>
                                            <p class="text-[11px] text-brand-moss">{{ \App\Models\NotificationChannel::labelForType($channel->type) }}</p>
                                        </div>
                                        <span class="shrink-0 rounded-md bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ trans_choice(':n use|:n uses', $channel->subscriptions_count, ['n' => $channel->subscriptions_count]) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                @endif
            </div>
        </section>
    @endif

    @if ($canManage && count($types) === 0)
        <section class="dply-card overflow-hidden">
            <div class="p-6 sm:p-7">
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-5 py-6 text-center">
                    <p class="text-sm font-medium text-brand-ink">{{ __('No notification channel types are enabled.') }}</p>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Add types via DPLY_NOTIFICATION_CHANNEL_TYPES when ready.') }}</p>
                </div>
            </div>
        </section>
    @elseif (! $canManage)
        <section class="dply-card overflow-hidden">
            <div class="p-6 sm:p-7">
                <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-cream/30 px-5 py-6 text-center">
                    <p class="text-sm font-medium text-brand-ink">{{ __('You can view channels here.') }}</p>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Ask an admin to add or change destinations.') }}</p>
                </div>
            </div>
        </section>
    @endif

    {{-- My channels --}}
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-megaphone class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Channels') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('My channels') }}</h3>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Search, edit, test, or remove destinations.') }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @if ($channelTotal > 0)
                    <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $channelTotal }}</span>
                @endif
                @if ($canManage && count($types) > 0)
                    <button
                        type="button"
                        wire:click="openCreateChannelModal"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add channel') }}
                    </button>
                @endif
            </div>
        </div>

        {{-- Toolbar: search box. --}}
        <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-6 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-7">
            <div class="w-full sm:max-w-sm">
                <label for="nc_my_channels_search" class="sr-only">{{ __('Search') }}</label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                        <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <input
                        id="nc_my_channels_search"
                        type="search"
                        wire:model.live.debounce.300ms="search"
                        placeholder="{{ __('Search channels…') }}"
                        autocomplete="off"
                        class="w-full rounded-lg border-brand-ink/15 bg-white py-2 ps-9 pe-3 text-sm text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                    />
                </div>
            </div>
        </div>

        @if ($channels->isEmpty())
            <div class="px-6 py-12 text-center sm:px-7">
                <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-bell-slash class="h-6 w-6" aria-hidden="true" />
                </span>
                <p class="mt-4 text-sm font-semibold text-brand-ink">
                    {{ $hasChannelSearch ? __('No channels match this search.') : __('No notification channels yet') }}
                </p>
                @if (! $hasChannelSearch)
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                        {{ __('Add a destination so alerts have somewhere to go — chat, email, webhook, mobile.') }}
                    </p>
                @endif
                @if ($hasChannelSearch)
                    <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                @elseif ($canManage && count($types) > 0)
                    <button
                        type="button"
                        wire:click="openCreateChannelModal"
                        class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add channel') }}
                    </button>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-brand-ink/5 text-left text-sm">
                    <thead class="bg-brand-sand/35 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                        <tr>
                            <th scope="col" class="px-6 py-2 sm:px-7">{{ __('Label') }}</th>
                            <th scope="col" class="px-4 py-2">{{ __('Type') }}</th>
                            <th scope="col" class="px-4 py-2 text-right">{{ __('Usages') }}</th>
                            <th scope="col" class="px-6 py-2 text-right sm:px-7">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5 bg-white">
                        @foreach ($channels as $channel)
                            <tr wire:key="nc-{{ $channel->id }}" class="transition-colors hover:bg-brand-sand/15">
                                @if ($editing_id === $channel->id)
                                    <td colspan="4" class="bg-brand-sand/20 px-6 py-4 sm:px-7">
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
                                                <x-primary-button type="submit">
                                                    <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    {{ __('Save changes') }}
                                                </x-primary-button>
                                                <x-secondary-button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-secondary-button>
                                            </div>
                                        </form>
                                    </td>
                                @else
                                    <td class="px-6 py-3 font-semibold text-brand-ink sm:px-7">{{ $channel->label }}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex items-center rounded-md bg-brand-sand/55 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                            {{ \App\Models\NotificationChannel::labelForType($channel->type) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-right font-mono tabular-nums text-brand-moss">{{ $channel->subscriptions_count }}</td>
                                    <td class="px-6 py-3 text-right sm:px-7">
                                        @if ($canManage)
                                            <div class="flex flex-wrap items-center justify-end gap-3">
                                                <button
                                                    type="button"
                                                    wire:click="sendTest({{ $channel->id }})"
                                                    wire:loading.attr="disabled"
                                                    wire:target="sendTest"
                                                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-sage hover:text-brand-ink disabled:opacity-50"
                                                >
                                                    <span wire:loading.remove wire:target="sendTest" class="inline-flex items-center gap-1.5">
                                                        <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                        {{ __('Test') }}
                                                    </span>
                                                    <span wire:loading wire:target="sendTest" class="inline-flex items-center gap-1.5">
                                                        <x-spinner variant="forest" size="sm" />
                                                        {{ __('Sending…') }}
                                                    </span>
                                                </button>
                                                <button type="button" wire:click="startEdit('{{ $channel->id }}')" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-ink hover:text-brand-sage">
                                                    <x-heroicon-o-pencil-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    {{ __('Edit') }}
                                                </button>
                                                <button
                                                    type="button"
                                                    wire:click="openConfirmActionModal('deleteChannel', ['{{ $channel->id }}'], @js(__('Delete notification channel')), @js(__('Remove this channel?')), @js(__('Delete')), true)"
                                                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-red-600 hover:text-red-700"
                                                >
                                                    <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                    {{ __('Delete') }}
                                                </button>
                                            </div>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>

@if ($canManage && count($types) > 0)
    <x-modal
        name="settings-create-channel-modal"
        :show="false"
        maxWidth="2xl"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
        focusable
    >
        <form wire:submit="createChannel" class="flex min-h-0 flex-1 flex-col">
            <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <x-icon-badge>
                    <x-heroicon-o-plus-circle class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Create notification channel') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">
                        {{ __('Connect chat, email, Pushover, webhooks, or mobile tokens. Credentials are stored encrypted.') }}
                    </p>
                </div>
            </div>

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="new_type_modal" :value="__('Type')" />
                        <x-select id="new_type_modal" wire:model.live="new_type">
                            @foreach ($types as $t)
                                <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                            @endforeach
                        </x-select>
                        @error('new_type')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                    <div>
                        <x-input-label for="new_label_modal" :value="__('Label')" />
                        <x-text-input id="new_label_modal" type="text" wire:model="new_label" placeholder="{{ __('e.g. #alerts') }}" required />
                        @error('new_label')
                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'new_', 'type' => $new_type])
            </div>

            <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeCreateChannelModal">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="createChannel"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="createChannel" class="inline-flex items-center gap-2">
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Create channel') }}
                    </span>
                    <span wire:loading wire:target="createChannel" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Creating…') }}
                    </span>
                </button>
            </div>
        </form>
    </x-modal>
@endif
