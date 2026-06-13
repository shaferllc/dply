@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $totalKeys = $sshKeysAll->count();
    $autoProvisionCount = $sshKeysAll->filter(fn ($k) => (bool) $k->provision_on_new_servers)->count();
    $reachableServers = $servers->count();
    $hasSshKeySearch = trim($ssh_keys_search ?? '') !== '';
@endphp

<div>
    <x-livewire-validation-errors />

    @push('breadcrumbs')
        <x-breadcrumb-trail doc-route="docs.index" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('SSH keys'), 'icon' => 'key'],
        ]" />
    @endpush

    {{-- Hero: positioning + at-a-glance key counts. --}}
    <section class="dply-card overflow-hidden">
        <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
            <div class="lg:col-span-7">
                <div class="flex items-start gap-3">
                    <x-icon-badge size="md">
                        <x-heroicon-o-key class="h-6 w-6" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Access') }}</p>
                        <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('SSH keys') }}</h2>
                        <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Save public keys on your account, optionally add them automatically to new servers, and deploy them to existing servers on demand.') }}
                        </p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-outline-link href="{{ route('settings.profile') }}" wire:navigate>
                        <x-heroicon-o-user-circle class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Back to profile') }}
                    </x-outline-link>
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add SSH key') }}
                    </button>
                </div>
            </div>
            <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Keys') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $totalKeys }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('saved|saved', $totalKeys) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('On your account') }}</p>
                </div>
                <div @class([
                    'rounded-2xl border px-4 py-3 shadow-sm',
                    'border-brand-sage/30 bg-brand-sage/8' => $autoProvisionCount > 0,
                    'border-brand-ink/10 bg-white' => $autoProvisionCount === 0,
                ])>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Auto-deploy') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $autoProvisionCount }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('key|keys', $autoProvisionCount) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('On every new server') }}</p>
                </div>
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Reachable') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $reachableServers }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('server|servers', $reachableServers) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Deploy targets') }}</p>
                </div>
            </dl>
        </div>
    </section>

    @if ($setup_source === 'servers.create')
        {{-- Pre-flight hint when arriving from the BYO server flow. --}}
        <section class="mt-6 rounded-2xl border border-brand-gold/35 bg-amber-50 px-5 py-5 shadow-sm">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="flex items-start gap-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $tonePalette['amber'] }}">
                        <x-heroicon-o-light-bulb class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="max-w-3xl">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-rust">{{ __('Before you create a BYO server') }}</p>
                        <h3 class="mt-1 text-lg font-semibold text-brand-ink">{{ __('Add at least one SSH key to your profile first') }}</h3>
                        <p class="mt-2 text-sm leading-6 text-brand-moss">
                            {{ __('Add a key from the modal here, optionally enable "Always provision to new servers," then return to the BYO server form.') }}
                        </p>
                        <ol class="mt-3 space-y-1 text-sm leading-6 text-brand-moss">
                            <li>1. {{ __('Generate or copy your SSH public key from your machine.') }}</li>
                            <li>2. {{ __('Open the add key modal and give the key a clear name like "Work laptop."') }}</li>
                            <li>3. {{ __('Turn on "Always provision to new servers" if this key should be added automatically.') }}</li>
                            <li>4. {{ __('Save the key, then return to create your BYO server.') }}</li>
                        </ol>
                        <p class="mt-3 text-[11px] text-brand-mist">
                            {{ __('Need help generating a key? Run') }}
                            <code class="rounded bg-brand-sand/70 px-1.5 py-0.5 font-mono text-[11px] text-brand-ink">ssh-keygen -t ed25519 -C "you@example.com"</code>
                            {{ __('in your terminal, then paste the contents of your .pub file here.') }}
                        </p>
                    </div>
                </div>
                @if ($returnUrl)
                    <a
                        href="{{ $returnUrl }}"
                        wire:navigate
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-m-chevron-left class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Back to create BYO server') }}
                    </a>
                @endif
            </div>
        </section>
    @endif

    <div class="mt-6 space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Directory') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Your keys') }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Save public keys, automatically push them onto new servers, or deploy them to specific existing servers on demand.') }}</p>
                </div>
                @if ($totalKeys > 0)
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                        class="shrink-0 inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add key') }}
                    </button>
                @endif
            </div>

            {{-- Toolbar: search box (only useful when there are keys). --}}
            @if ($sshKeysAll->isNotEmpty())
                <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-6 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-7">
                    <div class="w-full sm:max-w-sm">
                        <label for="ssh_keys_search" class="sr-only">{{ __('Search') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                                <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                            </span>
                            <input
                                id="ssh_keys_search"
                                type="search"
                                wire:model.live.debounce.300ms="ssh_keys_search"
                                placeholder="{{ __('Search keys by name…') }}"
                                autocomplete="off"
                                class="w-full rounded-lg border-brand-ink/15 bg-white py-2 ps-9 pe-3 text-sm text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                            />
                        </div>
                    </div>
                </div>
            @endif

            @if ($sshKeysAll->isEmpty())
                <div class="px-6 py-12 text-center sm:px-7">
                    <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-key class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No SSH keys yet') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                        {{ __('Add your first key to access connected servers, push to BYO hosts, or auto-provision newly created VMs.') }}
                    </p>
                    <button
                        type="button"
                        x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')"
                        class="mt-5 inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add SSH key') }}
                    </button>
                </div>
            @elseif ($hasSshKeySearch && $sshKeys->isEmpty())
                <div class="px-6 py-12 text-center sm:px-7">
                    <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No SSH keys match this search.') }}</p>
                    <button type="button" wire:click="$set('ssh_keys_search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($sshKeys as $key)
                        <li wire:key="ssh-key-{{ $key->id }}" class="flex items-center justify-between gap-4 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $key->name }}</span>
                                    @if ($key->provision_on_new_servers)
                                        <span class="inline-flex items-center gap-1 rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">
                                            <x-heroicon-m-check-circle class="h-3 w-3" aria-hidden="true" />
                                            {{ __('Auto') }}
                                        </span>
                                    @endif
                                </div>
                                <p class="mt-0.5 text-[11px] text-brand-mist">
                                    {{ $key->provision_on_new_servers
                                        ? __('Added automatically to new servers.')
                                        : __('Manual deploy only — push to specific servers when needed.') }}
                                </p>
                            </div>
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                @if ($reachableServers > 0)
                                    <x-secondary-button size="xs" type="button" wire:click="startDeploy('{{ $key->id }}')">
                                        <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Deploy') }}
                                    </x-secondary-button>
                                @endif
                                <x-secondary-button size="xs" type="button" wire:click="startEdit('{{ $key->id }}')">
                                    <x-heroicon-o-pencil-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Edit') }}
                                </x-secondary-button>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('deleteKey', ['{{ $key->id }}'], @js(__('Delete SSH key')), @js(__('Remove this key from your account? Linked copies on servers will be removed on the next sync.')), @js(__('Delete')), true)"
                                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-rose-700 shadow-sm hover:bg-rose-50"
                                >
                                    <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                    {{ __('Delete') }}
                                </button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

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
                            <span class="mt-0.5 block text-[11px] leading-relaxed text-brand-moss">{{ __('Newly created servers automatically receive this key.') }}</span>
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
                        <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Servers') }}</p>
                        <button type="button" wire:click="$set('deploy_server_ids', {{ json_encode($servers->pluck('id')->values()->all()) }})" class="text-[11px] font-semibold text-brand-sage hover:text-brand-ink">{{ __('Select all') }}</button>
                    </div>
                    <div class="mt-2 max-h-56 overflow-y-auto rounded-xl border border-brand-ink/10 divide-y divide-brand-ink/10">
                        @foreach ($servers as $server)
                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-brand-sand/20">
                                <input type="checkbox" wire:model.live="deploy_server_ids" value="{{ $server->id }}" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-sm font-semibold text-brand-ink">{{ $server->name }}</span>
                                    @if ($server->ip_address)
                                        <span class="block truncate font-mono text-[11px] text-brand-mist">{{ $server->ip_address }}</span>
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
