@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && filled($server->ssh_private_key);
    $consoleActionRun = \App\Models\ConsoleAction::query()
        ->where('subject_type', $server->getMorphClass())
        ->where('subject_id', $server->id)
        ->where('kind', 'system_user')
        ->whereNull('dismissed_at')
        ->orderByDesc('created_at')
        ->first();
    $orphanRows = collect($remote_rows)->filter(fn (array $r) => ! empty($r['is_orphan']))->values();
@endphp

<x-server-workspace-layout
    :server="$server"
    active="system-users"
    :title="__('System users')"
    :description="__('Linux accounts on this server. Sites pick from these for their file owner / PHP-FPM pool user; create the account here, then assign it to a site from the site\'s System user section.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-explainer tone="info">
        <p>{{ __('Each row is a Linux user the server already has in /etc/passwd. The site count shows how many Dply-managed sites are currently set to run as that user — those sites must be reassigned before you can remove the account.') }}</p>
        <p>{{ __('root, dply, and the configured deploy user are protected — Dply refuses to remove them. UID below 1000 (system accounts) is also blocked.') }}</p>
    </x-explainer>

    @if (! $opsReady)
        <section class="dply-card overflow-hidden border-amber-200">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-amber-100 text-amber-700 ring-1 ring-amber-200">
                    <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Setup') }}</p>
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Waiting on provisioning') }}</h3>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('System users management requires an SSH-ready server. Finish provisioning before managing accounts.') }}</p>
                </div>
            </div>
        </section>
    @else
        <div class="space-y-6">
            {{-- Server-scoped console-actions banner. Surfaces the in-flight + most-recent
                 system_user run (create, remove) for this server. --}}
            @include('livewire.partials.console-action-banner-static', [
                'run' => $consoleActionRun,
                'kindLabels' => (array) config('console_actions.kinds', []),
            ])

            <section class="{{ $card }} overflow-hidden">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-users class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Accounts') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Accounts on this server') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                {{ __('Loaded from /etc/passwd over SSH. Click a row to expand UID, home, shell, groups, and assigned sites.') }}
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                    {{ trans_choice('{0} no accounts|{1} :count account|[2,*] :count accounts', count($remote_rows), ['count' => count($remote_rows)]) }}
                                </span>
                                @if ($orphanRows->count() > 0)
                                    <span class="text-brand-mist/60">·</span>
                                    <span class="inline-flex items-center gap-1 text-amber-800">
                                        <x-heroicon-o-exclamation-triangle class="h-3 w-3" />
                                        {{ trans_choice('{1} :count orphan|[2,*] :count orphans', $orphanRows->count(), ['count' => $orphanRows->count()]) }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button
                            type="button"
                            wire:click="openCreateModal"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('Add a user') }}
                        </button>
                        <span class="hidden h-5 w-px bg-brand-ink/10 sm:block" aria-hidden="true"></span>
                        <button
                            type="button"
                            wire:click="loadUsers"
                            wire:loading.attr="disabled"
                            wire:target="loadUsers"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="loadUsers" />
                            <span wire:loading wire:target="loadUsers" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                <x-spinner variant="forest" size="sm" />
                            </span>
                            <span wire:loading.remove wire:target="loadUsers">{{ __('Sync now') }}</span>
                            <span wire:loading wire:target="loadUsers">{{ __('Syncing…') }}</span>
                        </button>
                    </div>
                </div>

                @if ($remote_rows !== [] && $orphanRows->count() > 0)
                    <div class="flex flex-col gap-3 border-b border-amber-200 bg-amber-50/70 px-6 py-3 text-sm text-amber-900 sm:flex-row sm:items-start sm:justify-between sm:px-8">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ trans_choice('{1} :count orphan account|[2,*] :count orphan accounts', $orphanRows->count(), ['count' => $orphanRows->count()]) }}</p>
                            <p class="mt-0.5 text-xs text-amber-900/80">
                                {{ __('Not protected and not assigned to any site:') }}
                                <span class="font-mono">{{ $orphanRows->pluck('username')->join(', ') }}</span>
                                — {{ __('expand a row to inspect, or remove them all in one go.') }}
                            </p>
                        </div>
                        <button
                            type="button"
                            wire:click="openRemoveOrphansConfirm"
                            wire:loading.attr="disabled"
                            wire:target="openRemoveOrphansConfirm,queueRemoveOrphans"
                            class="inline-flex h-8 shrink-0 items-center gap-1.5 self-start rounded-lg border border-red-200 bg-white px-3 text-xs font-semibold text-red-800 shadow-sm hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60 sm:self-center"
                        >
                            <x-heroicon-o-trash class="h-3.5 w-3.5" />
                            {{ __('Remove all orphans') }}
                        </button>
                    </div>
                @endif

                @if ($list_error)
                    <div class="border-b border-amber-200 bg-amber-50/70 px-6 py-3 text-sm text-amber-900 sm:px-8">
                        {{ $list_error }}
                    </div>
                @endif

                @if ($remote_rows === [])
                    <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss">
                            <x-heroicon-o-users class="h-6 w-6" />
                        </span>
                        <p class="text-sm font-medium text-brand-ink">{{ __('No users loaded yet.') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Click "Sync now" to read /etc/passwd over SSH and flag any orphan accounts.') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/8">
                        @foreach ($remote_rows as $row)
                            @php
                                $isLogin = trim((string) $server->ssh_user) !== '' && strtolower($server->ssh_user) === strtolower($row['username']);
                                $sites = $row['sites'] ?? [];
                                $groups = $row['groups'] ?? [];
                                $uid = $row['uid'] ?? null;
                                $home = (string) ($row['home'] ?? '');
                                $shell = (string) ($row['shell'] ?? '');
                                $isRemoving = in_array($row['username'], $pending_remove_usernames, true);
                            @endphp
                            <li class="px-6 py-4 sm:px-8" wire:key="su-{{ $row['username'] }}">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                    <span class="mt-0.5 hidden h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/30 text-brand-forest sm:inline-flex">
                                        <x-heroicon-o-user-circle class="h-5 w-5" />
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-mono text-sm font-semibold text-brand-ink">{{ $row['username'] }}</p>
                                            @if ($isLogin)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                                    <x-heroicon-m-user class="h-3 w-3" />
                                                    {{ __('login') }}
                                                </span>
                                            @endif
                                            @if (! empty($row['is_orphan']))
                                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-900 ring-1 ring-amber-200">
                                                    <x-heroicon-m-exclamation-triangle class="h-3 w-3" />
                                                    {{ __('Orphan') }}
                                                </span>
                                            @elseif (! empty($row['is_protected']))
                                                <span class="inline-flex items-center gap-1 rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                                    <x-heroicon-m-shield-check class="h-3 w-3" />
                                                    {{ __('Protected') }}
                                                </span>
                                            @endif
                                            @if ($uid !== null)
                                                <span class="inline-flex items-center rounded-full bg-white px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">
                                                    UID {{ $uid }}
                                                </span>
                                            @endif
                                        </div>

                                        <p class="mt-0.5 text-[11px] text-brand-mist">
                                            {{ trans_choice('{0} no sites|{1} :count site|[2,*] :count sites', $row['site_count'], ['count' => $row['site_count']]) }}
                                            @if ($shell !== '')
                                                <span class="text-brand-mist/60">·</span>
                                                <span class="font-mono">{{ $shell }}</span>
                                            @endif
                                        </p>

                                        <details class="mt-2 group">
                                            <summary class="cursor-pointer list-none text-[11px] font-medium uppercase tracking-wide text-brand-mist hover:text-brand-ink">
                                                <span class="inline-flex items-center gap-1">
                                                    <x-heroicon-o-chevron-down class="h-3 w-3 transition-transform group-open:rotate-180" />
                                                    <span class="group-open:hidden">{{ __('Show details') }}</span>
                                                    <span class="hidden group-open:inline">{{ __('Hide details') }}</span>
                                                </span>
                                            </summary>
                                            <div class="mt-2 space-y-3 rounded-lg bg-brand-sand/15 px-4 py-3">
                                                <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                                                    <div>
                                                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('UID') }}</dt>
                                                        <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $uid ?? '—' }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Login shell') }}</dt>
                                                        <dd class="mt-0.5 font-mono text-xs text-brand-ink">{{ $shell !== '' ? $shell : '—' }}</dd>
                                                    </div>
                                                    <div class="sm:col-span-2">
                                                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Home directory') }}</dt>
                                                        <dd class="mt-0.5 break-all font-mono text-xs text-brand-ink">{{ $home !== '' ? $home : '—' }}</dd>
                                                    </div>
                                                    <div class="sm:col-span-2">
                                                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ trans_choice('{0} Groups|{1} Group|[2,*] Groups', count($groups)) }}</dt>
                                                        <dd class="mt-1 flex flex-wrap gap-1">
                                                            @forelse ($groups as $g)
                                                                <span class="inline-flex items-center rounded-md bg-white px-1.5 py-0.5 font-mono text-[10px] font-medium text-brand-moss ring-1 ring-brand-ink/10">{{ $g }}</span>
                                                            @empty
                                                                <span class="text-xs italic text-brand-mist">{{ __('No group memberships detected.') }}</span>
                                                            @endforelse
                                                        </dd>
                                                    </div>
                                                    <div class="sm:col-span-2">
                                                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ trans_choice('{0} Assigned sites|{1} Assigned site|[2,*] Assigned sites', count($sites)) }}</dt>
                                                        <dd class="mt-1">
                                                            @if ($sites === [])
                                                                <p class="text-xs italic text-brand-mist">{{ __('No Dply-managed sites use this account.') }}</p>
                                                            @else
                                                                <ul class="space-y-1">
                                                                    @foreach ($sites as $s)
                                                                        <li>
                                                                            <a href="{{ route('sites.show', ['server' => $server, 'site' => $s['id']]) }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-medium text-brand-forest hover:underline">
                                                                                <x-heroicon-m-link class="h-3 w-3" />
                                                                                {{ $s['name'] }}
                                                                            </a>
                                                                        </li>
                                                                    @endforeach
                                                                </ul>
                                                            @endif
                                                        </dd>
                                                    </div>
                                                </dl>
                                            </div>
                                        </details>
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2 self-start sm:self-center">
                                        <button
                                            type="button"
                                            wire:click="openRemoveModal('{{ $row['username'] }}')"
                                            @disabled($isRemoving || ($row['site_count'] ?? 0) > 0 || ! empty($row['is_protected']))
                                            class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-red-200 bg-white px-3 text-xs font-semibold text-red-800 shadow-sm hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-60"
                                            title="{{ $isRemoving ? __('Removal in progress…') : (! empty($row['is_protected']) ? __('Protected accounts cannot be removed') : (($row['site_count'] ?? 0) > 0 ? __('Reassign all sites first') : __('Remove this account'))) }}"
                                        >
                                            @if ($isRemoving)
                                                <x-spinner variant="forest" size="sm" />
                                                {{ __('Removing…') }}
                                            @else
                                                <x-heroicon-o-trash class="h-3.5 w-3.5" />
                                                {{ __('Remove') }}
                                            @endif
                                        </button>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>

        {{-- Create modal --}}
        <x-modal
            name="server-system-user-create-modal"
            :show="false"
            maxWidth="lg"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('System user') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Create system user') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('Creates a Linux account on this server. It won\'t be assigned to any site — pick it from the site\'s System user section once it exists.') }}
                </p>
            </div>

            @php
                $webGroup = trim((string) config('site_settings.vm_site_file_web_group', 'www-data'));
            @endphp
            <div class="space-y-5 px-6 py-6">
                <div x-data>
                    <x-input-label for="server_system_user_new_username" :value="__('System user name')" />
                    {{-- x-on:input sanitises live so the operator can never type something
                         that would fail server-side validation: lowercases, strips anything
                         outside [a-z0-9_-], drops leading digits/dashes (useradd requires a
                         letter or underscore first), and caps at 32 chars. Pasted/autofilled
                         input fires `input` too, so this also catches bulk paste. --}}
                    <x-text-input
                        id="server_system_user_new_username"
                        wire:model="new_username"
                        x-on:input="$el.value = $el.value.toLowerCase().replace(/[^a-z0-9_-]/g, '').replace(/^[^a-z_]+/, '').slice(0, 32)"
                        maxlength="32"
                        inputmode="text"
                        autocapitalize="off"
                        spellcheck="false"
                        class="mt-1 block w-full font-mono text-sm"
                        placeholder="app-user"
                        autocomplete="off"
                    />
                    <p class="mt-1 text-xs text-brand-mist">
                        {{ __('Home directory will be /home/:user (created automatically).', ['user' => '<name>']) }}
                        {{ __('Lowercase letters, digits, _ and -; must start with a letter or underscore.') }}
                    </p>
                    <x-input-error :messages="$errors->get('new_username')" class="mt-1" />
                </div>

                <fieldset>
                    <legend class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Login shell') }}</legend>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        <label class="cursor-pointer">
                            <input type="radio" name="new_shell" value="/bin/bash" wire:model="new_shell" class="peer sr-only" />
                            <div class="rounded-xl border-2 px-4 py-3 transition peer-checked:border-brand-sage peer-checked:bg-brand-sage/10 peer-focus:ring-2 peer-focus:ring-brand-sage/30 {{ $new_shell === '/bin/bash' ? 'border-brand-sage bg-brand-sage/10' : 'border-brand-ink/12 bg-white hover:border-brand-ink/20' }}">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('Bash') }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Interactive shell. Use when this account should be able to SSH in.') }}</p>
                                <p class="mt-1 font-mono text-[10px] uppercase tracking-wide text-brand-mist">/bin/bash</p>
                            </div>
                        </label>
                        <label class="cursor-pointer">
                            <input type="radio" name="new_shell" value="/usr/sbin/nologin" wire:model="new_shell" class="peer sr-only" />
                            <div class="rounded-xl border-2 px-4 py-3 transition peer-checked:border-brand-sage peer-checked:bg-brand-sage/10 peer-focus:ring-2 peer-focus:ring-brand-sage/30 {{ $new_shell === '/usr/sbin/nologin' ? 'border-brand-sage bg-brand-sage/10' : 'border-brand-ink/12 bg-white hover:border-brand-ink/20' }}">
                                <p class="text-sm font-semibold text-brand-ink">{{ __('nologin') }}</p>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Service accounts only — runs FPM / queues but cannot SSH in.') }}</p>
                                <p class="mt-1 font-mono text-[10px] uppercase tracking-wide text-brand-mist">/usr/sbin/nologin</p>
                            </div>
                        </label>
                    </div>
                    <x-input-error :messages="$errors->get('new_shell')" class="mt-1" />
                </fieldset>

                <div class="space-y-2">
                    <legend class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Groups') }}</legend>
                    @if ($webGroup !== '')
                        <label class="flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="new_add_web_group" class="mt-0.5 rounded border-slate-300 text-brand-forest shadow-sm focus:ring-brand-forest">
                            <span class="min-w-0">
                                {{ __('Add to web group') }}
                                <span class="font-mono text-xs text-brand-moss">({{ $webGroup }})</span>
                                <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Lets nginx read site files owned by this user. Recommended for PHP-FPM/site owner accounts.') }}</span>
                            </span>
                        </label>
                    @endif
                    <label class="flex items-start gap-2 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-sm text-brand-ink">
                        <input type="checkbox" wire:model="new_sudo" class="mt-0.5 rounded border-slate-300 text-brand-forest shadow-sm focus:ring-brand-forest">
                        <span class="min-w-0">
                            {{ __('Sudo access') }}
                            <span class="mt-0.5 block text-xs text-brand-moss">{{ __('Grants this account full sudo. Leave off for service/FPM users.') }}</span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeCreateModal">{{ __('Cancel') }}</x-secondary-button>
                <x-primary-button type="button" wire:click="queueCreate" wire:loading.attr="disabled" wire:target="queueCreate">
                    <span wire:loading.remove wire:target="queueCreate">{{ __('Create') }}</span>
                    <span wire:loading wire:target="queueCreate" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Queueing…') }}
                    </span>
                </x-primary-button>
            </div>
        </x-modal>

        {{-- Remove modal --}}
        <x-modal
            name="server-system-user-remove-modal"
            :show="false"
            maxWidth="lg"
            overlayClass="bg-brand-ink/30"
            panelClass="dply-modal-panel"
            focusable
        >
            <div class="border-b border-brand-ink/10 px-6 py-5">
                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('System user') }}</p>
                <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Remove user from server') }}</h2>
                <p class="mt-2 text-sm leading-6 text-brand-moss">
                    {{ __('Deletes the Linux account from the host when policy allows. root, dply, and the deploy user cannot be removed. Type the username to confirm.') }}
                </p>
            </div>

            <div class="space-y-4 px-6 py-6">
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Removing') }}</p>
                    <p class="mt-1 break-all rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5 font-mono text-sm text-brand-ink">{{ $remove_username }}</p>
                </div>
                <div>
                    <x-input-label for="server_system_user_remove_confirm" :value="__('Type the username to confirm')" />
                    <x-text-input id="server_system_user_remove_confirm" wire:model="remove_confirm" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                    <x-input-error :messages="$errors->get('remove_confirm')" class="mt-1" />
                </div>
            </div>

            <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                <x-secondary-button type="button" wire:click="closeRemoveModal">{{ __('Cancel') }}</x-secondary-button>
                <x-danger-button type="button" wire:click="queueRemove" wire:loading.attr="disabled" wire:target="queueRemove">
                    <span wire:loading.remove wire:target="queueRemove">{{ __('Remove user') }}</span>
                    <span wire:loading wire:target="queueRemove" class="inline-flex items-center gap-2">
                        <x-spinner variant="cream" />
                        {{ __('Queueing…') }}
                    </span>
                </x-danger-button>
            </div>
        </x-modal>

        @include('livewire.partials.confirm-action-modal')
    @endif
</x-server-workspace-layout>
