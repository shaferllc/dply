@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $totalConfigs = $configurations->count();
    $providersInUse = $configurations->pluck('provider')->unique()->count();
    $allProviders = count(\App\Models\BackupConfiguration::providers());
    $hasBackupSearch = trim($search ?? '') !== '';

    // Per-provider chip tone, mirroring the engine chips on webserver
    // templates so users get a quick visual read on storage type.
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

    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
        ['label' => __('Backup destinations'), 'icon' => 'archive-box'],
    ]" />

    {{-- Hero: positioning + at-a-glance counts. --}}
    <section class="dply-card overflow-hidden">
        <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
            <div class="lg:col-span-7">
                <div class="flex items-start gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-archive-box-arrow-down class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Storage') }}</p>
                        <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Backup destinations') }}</h2>
                        <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                            @if ($organization)
                                {{ __('External storage shared by everyone in :org and reusable across every server. Add the bucket or remote here, then pick it when creating a schedule on a server.', ['org' => $organization->name]) }}
                            @else
                                {{ __('External storage shared by your organization and reusable across every server. Add the bucket or remote here, then pick it when creating a schedule on a server.') }}
                            @endif
                        </p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-outline-link href="{{ route('settings.profile') }}" wire:navigate>
                        <x-heroicon-o-user-circle class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Back to profile') }}
                    </x-outline-link>
                    <x-docs-link doc-route="docs.index">
                        <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Documentation') }}
                    </x-docs-link>
                    <button
                        type="button"
                        wire:click="openCreateModal"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add destination') }}
                    </button>
                </div>
            </div>
            <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Destinations') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $totalConfigs }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('saved|saved', $totalConfigs) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Reusable across servers') }}</p>
                </div>
                <div @class([
                    'rounded-2xl border px-4 py-3 shadow-sm',
                    'border-brand-sage/30 bg-brand-sage/8' => $providersInUse > 0,
                    'border-brand-ink/10 bg-white' => $providersInUse === 0,
                ])>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Providers') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $providersInUse }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('in use|in use', $providersInUse) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ $allProviders }} {{ __('supported') }}</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Scope') }}</dt>
                    <dd class="mt-1 truncate text-sm font-semibold text-brand-ink" title="{{ $organization?->name ?? __('Personal') }}">{{ $organization?->name ?? __('Personal') }}</dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ $organization ? __('Shared in this org') : __('Just you') }}</p>
                </div>
            </dl>
        </div>
    </section>

    <div class="mt-6 space-y-6">
        {{-- Edit panel (inline section card, mirrors create) --}}
        @if ($editing_id)
            <section wire:key="edit-{{ $editing_id }}" class="dply-card overflow-hidden border-brand-sage/30">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-pencil-square class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Edit') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Backup destination') }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Update the label or credentials, then save.') }}</p>
                    </div>
                </div>
                <div class="space-y-5 p-6 sm:p-7">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <x-input-label for="bc_edit_name" :value="__('Name')" />
                            <x-text-input id="bc_edit_name" wire:model="editForm.name" type="text" class="mt-1 block w-full" autocomplete="off" />
                            <x-input-error :messages="$errors->get('editForm.name')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="bc_edit_provider" :value="__('Storage provider')" />
                            <select id="bc_edit_provider" wire:model.live="editForm.provider" class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                                @foreach (\App\Models\BackupConfiguration::providers() as $p)
                                    <option value="{{ $p }}">{{ \App\Models\BackupConfiguration::labelForProvider($p) }}</option>
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('editForm.provider')" class="mt-2" />
                        </div>
                    </div>

                    @include('livewire.settings.partials.backup-provider-fields', ['formKey' => 'editForm', 'form' => $editForm])

                    <div class="flex flex-wrap justify-end gap-2 border-t border-brand-ink/10 pt-4">
                        <button type="button" wire:click="cancelEdit" class="px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                        <x-primary-button type="button" wire:click="updateConfiguration" wire:loading.attr="disabled" wire:target="updateConfiguration">
                            <span wire:loading.remove wire:target="updateConfiguration" class="inline-flex items-center gap-2">
                                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Save changes') }}
                            </span>
                            <span wire:loading wire:target="updateConfiguration" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Saving…') }}
                            </span>
                        </x-primary-button>
                    </div>
                </div>
            </section>
        @endif

        {{-- Saved destinations --}}
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Saved destinations') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Pick any of these when creating a backup schedule on a server.') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                        @if ($totalConfigs > 0)
                            <span class="rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-moss ring-1 ring-brand-ink/10">{{ $totalConfigs }}</span>
                        @endif
                        <button
                            type="button"
                            wire:click="openCreateModal"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Add destination') }}
                        </button>
                    </div>
            </div>

            @if ($totalConfigs > 0 || $hasBackupSearch)
                {{-- Toolbar: search. --}}
                <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-6 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-7">
                    <div class="w-full sm:max-w-sm">
                        <label for="bc_search" class="sr-only">{{ __('Search') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                                <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <input
                                id="bc_search"
                                type="search"
                                wire:model.live.debounce.300ms="search"
                                placeholder="{{ __('Search destinations by name…') }}"
                                autocomplete="off"
                                class="w-full rounded-lg border-brand-ink/15 bg-white py-2 ps-9 pe-3 text-sm text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            />
                        </div>
                    </div>
                </div>
            @endif

            @if (! $hasBackupSearch && $configurations->isEmpty())
                <div class="px-6 py-12 text-center sm:px-7">
                    <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-archive-box class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No backup destinations yet') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                        {{ __('Add a storage provider to start scheduling backups on your servers.') }}
                    </p>
                    <button
                        type="button"
                        wire:click="openCreateModal"
                        class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add destination') }}
                    </button>
                </div>
            @elseif ($hasBackupSearch && $configurations->isEmpty())
                <div class="px-6 py-12 text-center sm:px-7">
                    <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No destinations match this search.') }}</p>
                    <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($configurations as $row)
                        @php
                            $providerSlug = $row->provider;
                            $providerLabel = \App\Models\BackupConfiguration::labelForProvider($providerSlug);
                            $badgeClasses = $providerBadge($providerSlug);
                            $isEditing = $editing_id === (string) $row->id;
                        @endphp
                        <li wire:key="bc-{{ $row->id }}" @class([
                            'flex items-center justify-between gap-4 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7',
                            'bg-brand-sage/5' => $isEditing,
                        ])>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $row->name }}</span>
                                    <span class="inline-flex items-center rounded-md border px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide {{ $badgeClasses }}">{{ $providerLabel }}</span>
                                    @if ($isEditing)
                                        <span class="inline-flex items-center gap-1 rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">
                                            <x-heroicon-m-pencil-square class="h-3 w-3" aria-hidden="true" />
                                            {{ __('Editing') }}
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Added :time', ['time' => $row->created_at?->diffForHumans() ?? '—']) }}</p>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-3">
                                <button type="button" wire:click="startEdit('{{ $row->id }}')" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-ink hover:text-brand-sage">
                                    <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Edit') }}
                                </button>
                                <button type="button" wire:click="openConfirmActionModal('deleteConfiguration', ['{{ $row->id }}'], @js(__('Delete backup destination')), @js(__('Remove this backup destination? Schedules pointing at it stop firing until you pick a new one.')), @js(__('Delete')), true)" class="inline-flex items-center gap-1.5 text-xs font-semibold text-red-600 hover:text-red-700 hover:underline">
                                    <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    <x-modal
        name="backup-destination-modal"
        :show="false"
        maxWidth="2xl"
        overlayClass="bg-brand-ink/30"
        panelClass="dply-modal-panel overflow-hidden shadow-xl flex max-h-[min(90vh,880px)] flex-col"
        focusable
    >
        <form wire:submit="createConfiguration" class="flex min-h-0 flex-1 flex-col">
            <div class="flex shrink-0 items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-plus-circle class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('New') }}</p>
                    <h2 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Add backup destination') }}</h2>
                    <p class="mt-1 text-sm leading-6 text-brand-moss">
                        {{ __('Pick a storage provider and enter the connection details. Credentials are encrypted at rest and every server in the organization can use this destination.') }}
                    </p>
                </div>
            </div>

            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 py-6">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="bc_create_name_modal" :value="__('Name')" />
                        <x-text-input id="bc_create_name_modal" wire:model="createForm.name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Production S3') }}" autocomplete="off" />
                        <x-input-error :messages="$errors->get('createForm.name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="bc_create_provider_modal" :value="__('Storage provider')" />
                        <select id="bc_create_provider_modal" wire:model.live="createForm.provider" class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                            @foreach (\App\Models\BackupConfiguration::providers() as $p)
                                <option value="{{ $p }}">{{ \App\Models\BackupConfiguration::labelForProvider($p) }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('createForm.provider')" class="mt-2" />
                    </div>
                </div>

                @include('livewire.settings.partials.backup-provider-fields', ['formKey' => 'createForm', 'form' => $createForm])
            </div>

            <div class="flex shrink-0 flex-wrap justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeCreateModal">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="createConfiguration"
                    class="inline-flex items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="createConfiguration" class="inline-flex items-center gap-2">
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Create destination') }}
                    </span>
                    <span wire:loading wire:target="createConfiguration" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" size="sm" />
                        {{ __('Creating…') }}
                    </span>
                </button>
            </div>
        </form>
    </x-modal>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
