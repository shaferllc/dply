@php
    $totalKeys = $sshKeysAll->count();
    $autoProvisionCount = $sshKeysAll->filter(fn ($k) => (bool) $k->provision_on_new_servers)->count();
    $reachableServers = $servers->count();
    $hasSshKeySearch = trim($ssh_keys_search ?? '') !== '';
    // Header Add only when the list already has items — empty state owns the CTA.
    $showShellAdd = $totalKeys > 0;
@endphp

<div>
    <x-livewire-validation-errors />

    @push('breadcrumbs')
        <x-breadcrumb-trail doc-contextual :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('SSH keys'), 'icon' => 'key'],
        ]" />
    @endpush

    <x-profile-shell
        dense
        :title="__('SSH keys')"
        :description="__('Save public keys on your account, auto-add them to new servers, and deploy them on demand.')"
        icon="heroicon-o-key"
    >
        <x-slot:actions>
            {{-- No "Back to profile": the breadcrumb already covers it. --}}
            @if ($showShellAdd)
                <button
                    type="button"
                    x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                    class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Add SSH key') }}
                </button>
            @endif
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-px bg-brand-ink/5" aria-label="{{ __('SSH keys at a glance') }}">
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Keys') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $totalKeys }}</span>
                        <span class="truncate text-xs text-brand-moss">{{ __('on your account') }}</span>
                    </dd>
                </div>
                <div @class([
                    'px-3 py-2',
                    'bg-brand-sage/10' => $autoProvisionCount > 0,
                    'bg-white' => $autoProvisionCount === 0,
                ])>
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Auto-deploy') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $autoProvisionCount }}</span>
                        <span class="truncate text-xs text-brand-moss">{{ __('on new servers') }}</span>
                    </dd>
                </div>
                <div class="bg-white px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Reachable') }}</dt>
                    <dd class="mt-0.5 flex items-baseline gap-1.5">
                        <span class="font-mono text-base font-semibold tabular-nums text-brand-ink">{{ $reachableServers }}</span>
                        <span class="truncate text-xs text-brand-moss">{{ trans_choice('deploy target|deploy targets', $reachableServers) }}</span>
                    </dd>
                </div>
            </dl>
        </x-slot:stats>

        @if ($setup_source === 'servers.create')
            {{-- Pre-flight hint when arriving from the BYO server flow. --}}
            <div class="border-b border-brand-ink/10 bg-amber-50/60 px-3 py-2.5 sm:px-4">
                <div class="flex flex-col gap-2 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex items-start gap-2.5">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-md bg-amber-50 text-amber-900 ring-1 ring-amber-200">
                            <x-heroicon-o-light-bulb class="h-3.5 w-3.5" aria-hidden="true" />
                        </span>
                        <div class="max-w-3xl">
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Add at least one SSH key before creating a BYO server') }}</p>
                            <p class="mt-0.5 text-xs leading-relaxed text-brand-moss">
                                {{ __('Add a key below, optionally enable "Always provision to new servers," then return to the BYO server form.') }}
                            </p>
                            <p class="mt-1 text-xs text-brand-mist">
                                {{ __('No key yet? Run') }}
                                <code class="rounded bg-brand-sand/70 px-1 py-0.5 font-mono text-2xs text-brand-ink">ssh-keygen -t ed25519 -C "you@example.com"</code>
                                {{ __('and paste the .pub contents here.') }}
                            </p>
                        </div>
                    </div>
                    @if ($returnUrl)
                        <a
                            href="{{ $returnUrl }}"
                            wire:navigate
                            class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                        >
                            <x-heroicon-m-chevron-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Back to create BYO server') }}
                        </a>
                    @endif
                </div>
            </div>
        @endif

        @if ($sshKeysAll->isNotEmpty())
            <div class="flex flex-col gap-2 border-b border-brand-ink/10 px-3 py-2 sm:flex-row sm:items-center sm:justify-end sm:px-4">
                <div class="w-full sm:max-w-xs">
                    <label for="ssh_keys_search" class="sr-only">{{ __('Search') }}</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-2.5 text-brand-mist">
                            <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" aria-hidden="true" />
                        </span>
                        <input
                            id="ssh_keys_search"
                            type="search"
                            wire:model.live.debounce.300ms="ssh_keys_search"
                            placeholder="{{ __('Search keys by name…') }}"
                            autocomplete="off"
                            class="h-7 w-full rounded-md border-brand-ink/15 bg-white py-0 ps-8 pe-2.5 text-xs text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                        />
                    </div>
                </div>
            </div>
        @endif

        @if ($sshKeysAll->isEmpty())
            <div class="flex flex-col items-center justify-center px-3 py-10 text-center sm:px-4">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-key class="h-4 w-4" aria-hidden="true" />
                </span>
                <p class="mt-2.5 text-sm font-semibold text-brand-ink">{{ __('No SSH keys yet') }}</p>
                <p class="mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                    {{ __('Add your first key to access connected servers, push to BYO hosts, or auto-provision newly created VMs.') }}
                </p>
                <button
                    type="button"
                    x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                    class="mt-3 inline-flex h-7 items-center gap-1.5 rounded-md bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                >
                    <x-heroicon-o-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    {{ __('Add SSH key') }}
                </button>
            </div>
        @elseif ($hasSshKeySearch && $sshKeys->isEmpty())
            <div class="flex flex-col items-center justify-center px-3 py-10 text-center sm:px-4">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                    <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                </span>
                <p class="mt-2 text-sm font-medium text-brand-ink">{{ __('No SSH keys match this search.') }}</p>
                <button type="button" wire:click="$set('ssh_keys_search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($sshKeys as $key)
                    <li wire:key="ssh-key-{{ $key->id }}" class="flex items-center justify-between gap-3 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4">
                        <div class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                            <span class="truncate text-sm font-semibold text-brand-ink">{{ $key->name }}</span>
                            @if ($key->provision_on_new_servers)
                                <span class="inline-flex items-center gap-0.5 rounded border border-brand-sage/30 bg-brand-sage/15 px-1 py-px text-2xs font-semibold uppercase tracking-wide text-brand-forest">
                                    <x-heroicon-m-check-circle class="h-3 w-3" aria-hidden="true" />
                                    {{ __('Auto') }}
                                </span>
                            @endif
                            <span class="truncate text-xs text-brand-mist">
                                {{ $key->provision_on_new_servers
                                    ? __('Added automatically to new servers.')
                                    : __('Manual deploy only.') }}
                            </span>
                        </div>
                        <div class="flex shrink-0 items-center justify-end gap-1">
                            @if ($reachableServers > 0)
                                <button
                                    type="button"
                                    wire:click="startDeploy(@js($key->id))"
                                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-paper-airplane class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ __('Deploy') }}
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="startEdit(@js($key->id))"
                                class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-pencil-square class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Edit') }}
                            </button>
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('deleteKey', ['{{ $key->id }}'], @js(__('Delete SSH key')), @js(__('Remove this key from your account? Linked copies on servers will be removed on the next sync.')), @js(__('Delete')), true)"
                                class="inline-flex h-6 items-center gap-1 rounded-md border border-rose-200 bg-white px-2 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                aria-label="{{ __('Delete SSH key') }}"
                            >
                                <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Delete') }}
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-profile-shell>

    {{-- Edit modal --}}
    @if ($editing_id)
        @teleport('body')
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-brand-ink/40 p-4 sm:items-center" role="dialog" aria-modal="true">
            <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-xl" @click.stop>
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-pencil-square class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Edit') }}</p>
                        <h3 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('SSH key') }}</h3>
                    </div>
                </div>
                <div class="space-y-4 px-6 py-5">
                    <div>
                        <x-input-label for="ssh_edit_name" :value="__('Name')" />
                        <x-text-input id="ssh_edit_name" wire:model="edit_name" type="text" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('edit_name')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label for="ssh_edit_pub" :value="__('Public key')" />
                        <textarea id="ssh_edit_pub" wire:model="edit_public_key" rows="5" class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"></textarea>
                        <x-input-error :messages="$errors->get('edit_public_key')" class="mt-2" />
                    </div>
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-brand-ink/10 bg-brand-cream/30 px-3 py-2.5">
                        <input type="checkbox" wire:model.boolean="edit_provision_on_new_servers" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                        <span class="min-w-0">
                            <span class="text-sm font-medium text-brand-ink">{{ __('Always provision to new servers') }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-brand-moss">{{ __('Newly created servers automatically receive this key.') }}</span>
                        </span>
                    </label>
                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <button type="button" wire:click="cancelEdit" class="px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                    <x-primary-button type="button" wire:click="saveEdit" wire:loading.attr="disabled" wire:target="saveEdit">
                        <span wire:loading.remove wire:target="saveEdit" class="inline-flex items-center gap-2">
                            <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Save changes') }}
                        </span>
                        <span wire:loading wire:target="saveEdit" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Saving…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @endteleport
    @endif

    {{-- Deploy modal --}}
    @if ($deploying_id)
        @teleport('body')
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-brand-ink/40 p-4 sm:items-center" role="dialog" aria-modal="true">
            <div class="w-full max-w-lg overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-xl" @click.stop>
                <div class="flex items-start gap-3 border-b border-brand-ink/10 px-6 py-5">
                    <x-icon-badge>
                        <x-heroicon-o-paper-airplane class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Deploy') }}</p>
                        <h3 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Push key to servers') }}</h3>
                        <p class="mt-1 text-sm leading-6 text-brand-moss">{{ __('Choose servers to add or update this key on, then sync authorized_keys over SSH.') }}</p>
                    </div>
                </div>
                <div class="px-6 py-5">
                    <div class="flex items-center justify-between gap-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Servers') }}</p>
                        <button type="button" wire:click="$set('deploy_server_ids', {{ json_encode($servers->pluck('id')->values()->all()) }})" class="text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Select all') }}</button>
                    </div>
                    <div class="mt-2 max-h-56 overflow-y-auto rounded-xl border border-brand-ink/10 divide-y divide-brand-ink/10">
                        @foreach ($servers as $server)
                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-brand-sand/20">
                                <input type="checkbox" wire:model.live="deploy_server_ids" value="{{ $server->id }}" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-brand-ink">{{ $server->name }}</span>
                                    @if ($server->ip_address)
                                        <span class="block truncate font-mono text-xs text-brand-mist">{{ $server->ip_address }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('deploy_server_ids')" class="mt-2" />
                </div>
                <div class="flex flex-wrap justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4">
                    <button type="button" wire:click="cancelDeploy" class="px-3 py-2 text-sm font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                    <x-primary-button type="button" wire:click="confirmDeploy" wire:loading.attr="disabled" wire:target="confirmDeploy">
                        <span wire:loading.remove wire:target="confirmDeploy" class="inline-flex items-center gap-2">
                            <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Deploy now') }}
                        </span>
                        <span wire:loading wire:target="confirmDeploy" class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Deploying…') }}
                        </span>
                    </x-primary-button>
                </div>
            </div>
        </div>
        @endteleport
    @endif

    <x-slot name="modals">
        <livewire:profile.personal-ssh-key-modal />
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
