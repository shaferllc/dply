@php
    $totalConfigs = $configurations->count();
    $hasBackupSearch = trim($search ?? '') !== '';
    // Header Add only when the list already has items — empty state owns the CTA.
    $showShellAdd = $totalConfigs > 0;

    // Same provider chip tones as the Backups storage page so a bucket reads the
    // same on either surface.
    $providerBadge = function (string $provider): string {
        return match ($provider) {
            's3', 'aws_s3' => 'border-amber-200 bg-amber-50 text-amber-900',
            'b2', 'backblaze' => 'border-red-200 bg-red-50 text-red-700',
            'r2', 'cloudflare', 'cloudflare_r2' => 'border-sky-200 bg-sky-50 text-sky-700',
            'spaces', 'digitalocean_spaces' => 'border-violet-200 bg-violet-50 text-violet-700',
            'gcs', 'google_cloud_storage' => 'border-emerald-200 bg-emerald-50 text-emerald-700',
            'sftp', 'ssh' => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
            default => 'border-brand-ink/10 bg-brand-sand/40 text-brand-moss',
        };
    };
@endphp

<div>
    <x-livewire-validation-errors />

    @push('breadcrumbs')
        <x-breadcrumb-trail doc-contextual :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Backup destinations'), 'icon' => 'archive-box'],
        ]" />
    @endpush

    <x-profile-shell
        dense
        :title="__('Backup destinations')"
        :description="__('Buckets and remotes your organization owns. Pick one when scheduling a backup on a server.')"
        icon="heroicon-o-archive-box"
    >
        <x-slot:actions>
            {{-- The Backups product page is the same rows plus usage analytics;
                 link across rather than duplicating the console here. --}}
            <a
                href="{{ route('backups.storage') }}"
                wire:navigate
                class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
            >
                <x-heroicon-o-chart-bar class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('Usage & coverage') }}
            </a>
            @if ($showShellAdd)
                <button
                    type="button"
                    wire:click="openDestinationModal"
                    class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Add destination') }}
                </button>
            @endif
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('Backup destinations at a glance') }}">
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Destinations') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $metrics['destinations'] }}</span>
                        <span class="truncate text-xs text-brand-moss">{{ trans_choice('bucket or remote|buckets and remotes', $metrics['destinations']) }}</span>
                    </dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Providers') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $metrics['providers'] }}</span>
                        <span class="truncate text-xs text-brand-moss">/ {{ $metrics['allProviders'] }} {{ __('supported') }}</span>
                    </dd>
                </div>
                <div @class([
                    'px-3 py-2',
                    'bg-amber-50' => $metrics['dumpSchedules'] > 0 && $metrics['onServer'] > 0,
                    'bg-white' => ! ($metrics['dumpSchedules'] > 0 && $metrics['onServer'] > 0),
                ])>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Shipping') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $metrics['shipping'] }}</span>
                        <span class="truncate text-xs text-brand-moss" title="{{ __('Active database schedules that ship off the server.') }}">
                            {{ __('of :n db schedules', ['n' => $metrics['dumpSchedules']]) }}
                        </span>
                    </dd>
                </div>
            </dl>
        </x-slot:stats>

        @if (! $organization)
            <div class="px-3 py-2.5 sm:px-4">
                <div class="rounded-md border border-dashed border-brand-ink/15 bg-brand-cream/30 px-3 py-3 text-center">
                    <p class="text-sm font-medium text-brand-ink">{{ __('No current organization.') }}</p>
                    <p class="mt-1 text-xs text-brand-mist">{{ __('Create or join an organization to add backup destinations.') }}</p>
                </div>
            </div>
        @else
            {{-- Edit panel: identical fields to the Backups page, same actions on
                 the shared component. --}}
            @if ($editing_id)
                <div wire:key="edit-{{ $editing_id }}" class="border-b border-brand-ink/10 bg-brand-sage/5">
                    <x-workspace-panel-head
                        dense
                        class="border-b border-brand-ink/10"
                        icon="heroicon-o-pencil-square"
                        :title="__('Edit destination')"
                        :note="__('Update the label or credentials, then save.')"
                    />
                    <div class="space-y-2.5 px-3 py-2.5 sm:px-4">
                        <div class="grid gap-2.5 sm:grid-cols-2">
                            <div>
                                <x-input-label for="bc_edit_name" :value="__('Name')" />
                                <x-text-input id="bc_edit_name" wire:model="editForm.name" type="text" class="mt-1 block w-full" autocomplete="off" />
                                <x-input-error :messages="$errors->get('editForm.name')" class="mt-1" />
                            </div>
                            <div>
                                <x-input-label for="bc_edit_provider" :value="__('Storage provider')" />
                                <select id="bc_edit_provider" wire:model.live="editForm.provider" class="mt-1 block w-full rounded-md border-brand-ink/15 bg-white px-2.5 py-1.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                    @foreach (\App\Models\BackupConfiguration::providers() as $p)
                                        @php $providerAvailable = \App\Models\BackupConfiguration::isProviderAvailable($p); @endphp
                                        <option value="{{ $p }}" @disabled(! $providerAvailable)>{{ \App\Models\BackupConfiguration::labelForProvider($p) }}@unless ($providerAvailable) — {{ __('coming soon') }}@endunless</option>
                                    @endforeach
                                </select>
                                <x-input-error :messages="$errors->get('editForm.provider')" class="mt-1" />
                            </div>
                        </div>

                        @include('livewire.settings.partials.backup-provider-fields', ['formKey' => 'editForm', 'form' => $editForm])

                        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 pt-2">
                            <button type="button" wire:click="cancelEdit" class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                            <button
                                type="button"
                                wire:click="updateConfiguration"
                                wire:loading.attr="disabled"
                                wire:target="updateConfiguration"
                                class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest disabled:opacity-60"
                            >
                                <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="updateConfiguration" aria-hidden="true" />
                                <span wire:loading wire:target="updateConfiguration" class="inline-flex h-3.5 w-3.5 shrink-0 items-center justify-center"><x-spinner variant="cream" size="sm" /></span>
                                {{ __('Save changes') }}
                            </button>
                        </div>
                    </div>
                </div>
            @endif

            @if ($totalConfigs > 0 || $hasBackupSearch)
                <div class="flex flex-col gap-2 border-b border-brand-ink/10 px-3 py-2 sm:flex-row sm:items-center sm:justify-end sm:px-4">
                    <div class="w-full sm:max-w-xs">
                        <label for="bc_search" class="sr-only">{{ __('Search') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                                <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
                            </span>
                            <input
                                id="bc_search"
                                type="search"
                                wire:model.live.debounce.300ms="search"
                                placeholder="{{ __('Search destinations by name…') }}"
                                autocomplete="off"
                                class="h-7 w-full rounded-md border-brand-ink/15 bg-white py-0 ps-8 pe-2.5 text-xs text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            />
                        </div>
                    </div>
                </div>
            @endif

            @if ($configurations->isEmpty())
                <div class="flex flex-col items-center justify-center px-3 py-10 text-center sm:px-4">
                    <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-dynamic-component :component="$hasBackupSearch ? 'heroicon-o-magnifying-glass' : 'heroicon-o-archive-box'" class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <p class="mt-2.5 text-sm font-semibold text-brand-ink">
                        {{ $hasBackupSearch ? __('No destinations match this search.') : __('No backup destinations yet') }}
                    </p>
                    @if ($hasBackupSearch)
                        <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                    @else
                        <p class="mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                            {{ __('Add a storage provider to start scheduling backups on your servers.') }}
                        </p>
                        <button
                            type="button"
                            wire:click="openDestinationModal"
                            class="mt-3 inline-flex h-7 items-center gap-1.5 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Add destination') }}
                        </button>
                    @endif
                </div>
            @else
                {{-- Management list: identity, what points at it, actions. The
                     write trends and coverage dial stay on /backups/storage. --}}
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($configurations as $row)
                        @php
                            $providerLabel = \App\Models\BackupConfiguration::labelForProvider($row->provider);
                            $isEditing = $editing_id === (string) $row->id;
                            $stats = $usage[$row->id] ?? ['schedules' => 0, 'writes' => 0, 'bytes' => 0, 'lastWriteAt' => null];
                            $unused = $stats['schedules'] === 0 && $stats['writes'] === 0;

                            // The confirm copy quotes the real number: "stops 3
                            // schedules" is a decision, "stops schedules" is a shrug.
                            $deleteWarning = $stats['schedules'] > 0
                                ? trans_choice(
                                    'Remove this destination? :count schedule points at it and stops shipping until you pick a new one. Backups already written to the bucket are not touched.|Remove this destination? :count schedules point at it and stop shipping until you pick a new one. Backups already written to the bucket are not touched.',
                                    $stats['schedules'],
                                    ['count' => $stats['schedules']],
                                )
                                : __('Remove this destination? Nothing points at it right now. Backups already written to the bucket are not touched.');
                        @endphp
                        <li wire:key="bc-{{ $row->id }}" @class([
                            'flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4',
                            'bg-brand-sage/5' => $isEditing,
                        ])>
                            <div class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                <span class="truncate text-sm font-semibold text-brand-ink">{{ $row->name }}</span>
                                <span class="inline-flex shrink-0 items-center rounded border px-1.5 py-px text-2xs font-semibold uppercase tracking-wide {{ $providerBadge($row->provider) }}">{{ $providerLabel }}</span>
                                @if ($stats['schedules'] > 0)
                                    <span class="inline-flex shrink-0 items-center gap-1 rounded bg-brand-sage/20 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-brand-forest">
                                        <span class="h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                        {{ trans_choice(':count schedule|:count schedules', $stats['schedules'], ['count' => $stats['schedules']]) }}
                                    </span>
                                @elseif ($stats['writes'] > 0)
                                    <span class="inline-flex shrink-0 items-center rounded bg-brand-gold/25 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-amber-800">{{ __('No schedule') }}</span>
                                @else
                                    <span class="inline-flex shrink-0 items-center rounded bg-brand-sand/50 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Unused') }}</span>
                                @endif
                                <span class="truncate text-xs text-brand-mist">
                                    @if ($stats['lastWriteAt'])
                                        {{ __('last write :time', ['time' => $stats['lastWriteAt']->diffForHumans(short: true)]) }}@if ($stats['bytes'] > 0) · {{ \Illuminate\Support\Number::fileSize($stats['bytes']) }}@endif
                                    @else
                                        {{ __('never written to') }}
                                    @endif
                                </span>
                            </div>
                            <div class="flex shrink-0 items-center gap-1">
                                <button
                                    type="button"
                                    wire:click="startEdit('{{ $row->id }}')"
                                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Edit') }}
                                </button>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('deleteConfiguration', ['{{ $row->id }}'], @js(__('Remove backup destination')), @js($deleteWarning), @js(__('Remove')), true)"
                                    class="inline-flex h-6 items-center gap-1 rounded-md border border-rose-200 bg-white px-2 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                >
                                    <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Remove') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        @endif
    </x-profile-shell>

    {{-- The shared add-destination modal: "connect existing" pastes keys for a
         bucket you already have, "provision" creates a brand-new one on the
         provider using a connected cloud token. Same dialog the server workspace
         and /backups/storage open — see ManagesBackupDestinationModal. --}}
    @include('livewire.servers.partials.backups._add-destination-modal')

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
