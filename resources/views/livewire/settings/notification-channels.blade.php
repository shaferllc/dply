<div>
    @if (! empty($useOrgShell))
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <x-organization-shell :organization="$organization" :section="$orgShellSection ?? 'notifications'">
    @endif

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

    <header class="mb-8 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold text-brand-ink">{{ $pageTitle }}</h1>
            <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">{{ $intro }}</p>
        </div>
        @if (! empty($showBulkAssign ?? false))
            <a
                href="{{ route('profile.notification-channels.bulk-assign') }}"
                wire:navigate
                class="shrink-0 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/50"
            >{{ __('Bulk assign notifications') }}</a>
        @endif
    </header>

    @if ($flash_success)
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900" role="status">{{ $flash_success }}</div>
    @endif
    @if ($flash_error)
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900" role="alert">{{ $flash_error }}</div>
    @endif

    <div class="space-y-10">
        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Create notification channel') }}</h2>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Connect chat, email, Pushover, webhooks, or mobile tokens. Credentials are stored encrypted.') }}</p>
            </div>
            @if ($canManage && count($types) === 0)
                <div class="px-6 py-6 sm:px-8 text-sm text-brand-moss">
                    {{ __('No notification channel types are enabled. Add types in configuration (DPLY_NOTIFICATION_CHANNEL_TYPES) when ready.') }}
                </div>
            @elseif ($canManage)
                <form wire:submit="createChannel" class="p-6 sm:p-8 space-y-5">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <x-input-label for="new_type" :value="__('Type')" />
                            <select
                                id="new_type"
                                wire:model.live="new_type"
                                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            >
                                @foreach ($types as $t)
                                    <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                                @endforeach
                            </select>
                            @error('new_type')
                                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <x-input-label for="new_label" :value="__('Label')" />
                            <input
                                id="new_label"
                                type="text"
                                wire:model="new_label"
                                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                placeholder="{{ __('e.g. #alerts') }}"
                                required
                            />
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
                <div class="px-6 py-6 sm:px-8 text-sm text-brand-moss">{{ __('You can view channels here. Ask an admin to add or change destinations.') }}</div>
            @endif
        </section>

        <section class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Channels') }}</h2>
                <input
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search…') }}"
                    class="rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage w-full max-w-xs"
                />
            </div>
            <div class="overflow-x-auto">
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
                                                    <select
                                                        id="edit_type"
                                                        wire:model.live="edit_type"
                                                        class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm"
                                                    >
                                                        @foreach ($typesForEdit as $t)
                                                            <option value="{{ $t }}">{{ \App\Models\NotificationChannel::labelForType($t) }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error('edit_type')
                                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                                    @enderror
                                                </div>
                                                <div>
                                                    <x-input-label for="edit_label" :value="__('Label')" />
                                                    <input id="edit_label" type="text" wire:model="edit_label" class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm" />
                                                    @error('edit_label')
                                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                                    @enderror
                                                </div>
                                            </div>
                                            @include('livewire.settings.partials.notification-channel-fields', ['prefix' => 'edit_', 'type' => $edit_type])
                                            <div class="flex flex-wrap gap-2">
                                                <x-primary-button type="submit">{{ __('Save') }}</x-primary-button>
                                                <button type="button" wire:click="cancelEdit" class="rounded-xl border border-brand-ink/15 px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/60">{{ __('Cancel') }}</button>
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
                                                <button type="button" wire:click="startEdit({{ $channel->id }})" class="text-sm font-medium text-brand-ink hover:underline">{{ __('Edit') }}</button>
                                                <button
                                                    type="button"
                                                    wire:click="deleteChannel({{ $channel->id }})"
                                                    wire:confirm="{{ __('Remove this channel?') }}"
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
            </div>
        </section>
    </div>

    @if (! empty($useOrgShell))
            </x-organization-shell>
        </div>
    @endif
</div>
