@php
    $card = 'dply-card overflow-hidden';
    $opsReady = $server->isReady() && $server->hasAnySshPrivateKey();
@endphp

<x-server-workspace-layout
    :server="$server"
    active="ssh"
    :title="__('SSH keys')"
    :description="__('Authorize keys, preview drift, audit changes, and sync authorized_keys.')"
>
    @include('livewire.servers.partials.workspace-flashes')
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    @if ($opsReady)
        <div class="space-y-6">
        <x-server-workspace-tablist :aria-label="__('SSH keys workspace')">
            <x-server-workspace-tab id="ssh-tab-keys" :active="$ssh_workspace_tab === 'keys'" wire:click="$set('ssh_workspace_tab', 'keys')">
                <span class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-key class="h-4 w-4" aria-hidden="true" />
                    {{ __('Keys') }}
                </span>
            </x-server-workspace-tab>
            <x-server-workspace-tab id="ssh-tab-preview" :active="$ssh_workspace_tab === 'preview'" wire:click="$set('ssh_workspace_tab', 'preview')">
                <span class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-arrows-right-left class="h-4 w-4" aria-hidden="true" />
                    {{ __('Drift') }}
                </span>
            </x-server-workspace-tab>
            <x-server-workspace-tab id="ssh-tab-advanced" :active="$ssh_workspace_tab === 'advanced'" wire:click="$set('ssh_workspace_tab', 'advanced')">
                <span class="inline-flex items-center gap-1.5">
                    <x-heroicon-o-adjustments-horizontal class="h-4 w-4" aria-hidden="true" />
                    {{ __('Advanced') }}
                </span>
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        <x-server-workspace-tab-panel
            id="ssh-panel-keys"
            labelled-by="ssh-tab-keys"
            :hidden="$ssh_workspace_tab !== 'keys'"
            panel-class="space-y-6"
        >
            @php
                // Source mode is implicit: if a profile key is selected, we're in "profile" mode;
                // otherwise default to "paste" (the form fields). "Generate" is a one-shot button.
                $sourceMode = $profile_key_id ? 'profile' : 'paste';
            @endphp
            <div class="{{ $card }} overflow-hidden">
                {{-- Header row: title + inline sync/review buttons (no big sidebar callout) --}}
                <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-8">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Add an SSH key') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">
                            {{ __('Pick a source, then sync to write the key to the server\'s authorized_keys.') }}
                            <a href="https://www.ssh.com/academy/ssh/public-key-authentication" target="_blank" rel="noopener" class="font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">{{ __('Learn more') }}</a>
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <button type="button" wire:click="syncAuthorizedKeys" wire:loading.attr="disabled" wire:target="syncAuthorizedKeys" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-50">
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                            <span wire:loading.remove wire:target="syncAuthorizedKeys">{{ __('Sync now') }}</span>
                            <span wire:loading wire:target="syncAuthorizedKeys">{{ __('Syncing…') }}</span>
                        </button>
                        <button type="button" wire:click="$set('ssh_workspace_tab', 'preview')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" />
                            {{ __('Review drift') }}
                        </button>
                    </div>
                </div>

                @if (! $serverHasPersonalProfileKey)
                    <div class="mx-6 mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900 sm:mx-8">
                        <p class="min-w-0 leading-6">
                            <span class="font-semibold">{{ __('No personal key on this server yet.') }}</span>
                            {{ __('Attach one below, then sync.') }}
                        </p>
                        @if ($profileKeys->isEmpty())
                            <button type="button" x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                                <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                {{ __('Add profile key') }}
                            </button>
                        @endif
                    </div>
                @endif

                <div class="px-6 py-6 sm:px-8 sm:py-7">
                    {{-- Source picker: Profile / Paste / Generate --}}
                    @php
                        $hasProfile = $profileKeys->isNotEmpty();
                    @endphp
                    <fieldset class="space-y-2">
                        <legend class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Source') }}</legend>
                        <div class="grid gap-2 sm:grid-cols-3">
                            @if ($hasProfile)
                                <label class="cursor-pointer">
                                    <input type="radio" name="source" value="profile" class="peer sr-only"
                                        @checked($sourceMode === 'profile')
                                        x-on:click="$wire.set('profile_key_id', $wire.profile_key_id || '{{ $profileKeys->first()?->id }}')" />
                                    <div class="rounded-xl border-2 px-4 py-3 transition peer-checked:border-brand-sage peer-checked:bg-brand-sage/10 peer-focus:ring-2 peer-focus:ring-brand-sage/30 {{ $sourceMode === 'profile' ? 'border-brand-sage bg-brand-sage/10' : 'border-brand-ink/12 bg-white hover:border-brand-ink/20' }}">
                                        <div class="flex items-center gap-2">
                                            <x-heroicon-o-user-circle class="h-4 w-4 text-brand-forest" />
                                            <span class="text-sm font-semibold text-brand-ink">{{ __('From profile') }}</span>
                                        </div>
                                        <p class="mt-0.5 text-xs text-brand-moss">{{ __('Use a key already saved on your account.') }}</p>
                                    </div>
                                </label>
                            @endif
                            <label class="cursor-pointer">
                                <input type="radio" name="source" value="paste" class="peer sr-only"
                                    @checked($sourceMode === 'paste')
                                    wire:click="clearProfileSelection" />
                                <div class="rounded-xl border-2 px-4 py-3 transition peer-checked:border-brand-sage peer-checked:bg-brand-sage/10 peer-focus:ring-2 peer-focus:ring-brand-sage/30 {{ $sourceMode === 'paste' ? 'border-brand-sage bg-brand-sage/10' : 'border-brand-ink/12 bg-white hover:border-brand-ink/20' }}">
                                    <div class="flex items-center gap-2">
                                        <x-heroicon-o-clipboard-document class="h-4 w-4 text-brand-forest" />
                                        <span class="text-sm font-semibold text-brand-ink">{{ __('Paste a key') }}</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-brand-moss">{{ __('Paste a public key you already have.') }}</p>
                                </div>
                            </label>
                            <button
                                type="button"
                                wire:click="generateNewAuthorizedKeyPair"
                                wire:loading.attr="disabled"
                                wire:target="generateNewAuthorizedKeyPair"
                                class="block w-full text-left rounded-xl border-2 border-brand-ink/12 bg-white px-4 py-3 transition hover:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30 disabled:opacity-50"
                            >
                                <div class="flex items-center gap-2">
                                    <x-heroicon-o-sparkles class="h-4 w-4 text-brand-forest" />
                                    <span class="text-sm font-semibold text-brand-ink">
                                        <span wire:loading.remove wire:target="generateNewAuthorizedKeyPair">{{ __('Generate new') }}</span>
                                        <span wire:loading wire:target="generateNewAuthorizedKeyPair">{{ __('Generating…') }}</span>
                                    </span>
                                </div>
                                <p class="mt-0.5 text-xs text-brand-moss">{{ __('Ed25519 keypair in your browser. Save the private half.') }}</p>
                            </button>
                        </div>
                    </fieldset>

                    {{-- Profile picker (only when in profile mode) --}}
                    @if ($sourceMode === 'profile' && $hasProfile)
                        <div class="mt-5">
                            <x-input-label for="profile_key_id" :value="__('Profile key')" />
                            <select id="profile_key_id" wire:model.live="profile_key_id" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                                @foreach ($profileKeys as $pk)
                                    <option value="{{ $pk->id }}">{{ $pk->name }}</option>
                                @endforeach
                            </select>
                            <p class="mt-1 text-xs text-brand-moss">{{ __('We use the saved key content — name and key fields below are populated automatically.') }}</p>
                        </div>
                    @endif

                    <form wire:submit="addAuthorizedKey" class="mt-5 space-y-4">
                        {{-- Name + key (always present, sometimes disabled when source=profile) --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="new_auth_name" :value="__('Name')" />
                                <x-text-input id="new_auth_name" wire:model="new_auth_name" class="mt-1 block w-full" placeholder="{{ __('e.g. Work laptop') }}" :disabled="(bool) $profile_key_id" />
                            </div>
                            <div>
                                <x-input-label for="new_target_linux_user" :value="__('Target user on server')" />
                                <div class="mt-1 flex items-stretch gap-2">
                                    <select id="new_target_linux_user" wire:model="new_target_linux_user" class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                                        @foreach ($system_users as $u)
                                            <option value="{{ $u }}">{{ $u }}@if ($u === $server->ssh_user) ({{ __('login') }})@endif</option>
                                        @endforeach
                                    </select>
                                    <button type="button" wire:click="loadSystemUsers" wire:loading.attr="disabled" wire:target="loadSystemUsers" title="{{ __('Reload /etc/passwd users over SSH') }}" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-2.5 text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                        <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div>
                            <x-input-label for="new_auth_key" :value="__('Public key')" />
                            <textarea
                                id="new_auth_key"
                                wire:model="new_auth_key"
                                rows="3"
                                @disabled($profile_key_id)
                                class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 disabled:bg-brand-sand/50"
                                placeholder="ssh-ed25519 AAAA…"
                            ></textarea>
                        </div>

                        <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                            <summary class="cursor-pointer text-xs font-semibold uppercase tracking-wide text-brand-mist">
                                <span class="inline-flex items-center gap-1.5">
                                    <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                                    {{ __('Advanced — rotation reminder') }}
                                </span>
                            </summary>
                            <div class="mt-3">
                                <x-input-label for="new_review_after" :value="__('Review after (optional)')" />
                                <x-text-input id="new_review_after" type="date" wire:model="new_review_after" class="mt-1 block w-full max-w-xs" />
                                <p class="mt-1 text-xs text-brand-moss">{{ __('Triggers a rotation reminder email to the server owner on this date.') }}</p>
                            </div>
                        </details>

                        <div class="flex flex-wrap items-center gap-2 border-t border-brand-ink/5 pt-4">
                            <x-primary-button type="submit" class="!py-2" wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="addAuthorizedKey">{{ __('Add SSH key') }}</span>
                                <span wire:loading wire:target="addAuthorizedKey">{{ __('Adding…') }}</span>
                            </x-primary-button>
                            <button type="button" wire:click="syncAuthorizedKeys" wire:loading.attr="disabled" wire:target="syncAuthorizedKeys" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50">
                                <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                                <span wire:loading.remove wire:target="syncAuthorizedKeys">{{ __('Add & sync now') }}</span>
                                <span wire:loading wire:target="syncAuthorizedKeys">{{ __('Syncing…') }}</span>
                            </button>
                            <p class="text-xs text-brand-moss">{{ __('Saved here · written to the server only on sync.') }}</p>
                        </div>
                    </form>
                </div>
            </div>

            @if ($orgKeys->isNotEmpty() || $teamKeys->isNotEmpty())
                <div class="{{ $card }} mt-6 p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Organization & team keys') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Use saved organization or team keys when you want a shared key on this server without pasting it again.') }}</p>
                    <div class="mt-4 grid gap-6 lg:grid-cols-2">
                        @if ($orgKeys->isNotEmpty())
                            <form wire:submit="deployOrganizationKey" class="space-y-3 rounded-xl border border-brand-ink/10 p-4">
                                <x-input-label for="deploy_org_key_id" :value="__('Organization key')" />
                                <select id="deploy_org_key_id" wire:model="deploy_org_key_id" class="block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm">
                                    <option value="">{{ __('Choose…') }}</option>
                                    @foreach ($orgKeys as $ok)
                                        <option value="{{ $ok->id }}">{{ $ok->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-label for="deploy_target_linux_user_org" :value="__('System user')" />
                                <select id="deploy_target_linux_user_org" wire:model="deploy_target_linux_user" class="block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm">
                                    @foreach ($system_users as $u)
                                        <option value="{{ $u }}">{{ $u }}</option>
                                    @endforeach
                                </select>
                                <x-primary-button type="submit" class="!py-2" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="deployOrganizationKey">{{ __('Deploy org key') }}</span>
                                    <span wire:loading wire:target="deployOrganizationKey">{{ __('Deploying…') }}</span>
                                </x-primary-button>
                            </form>
                        @endif
                        @if ($teamKeys->isNotEmpty())
                            <form wire:submit="deployTeamKey" class="space-y-3 rounded-xl border border-brand-ink/10 p-4">
                                <x-input-label for="deploy_team_key_id" :value="__('Team key')" />
                                <select id="deploy_team_key_id" wire:model="deploy_team_key_id" class="block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm">
                                    <option value="">{{ __('Choose…') }}</option>
                                    @foreach ($teamKeys as $tk)
                                        <option value="{{ $tk->id }}">{{ $tk->name }}</option>
                                    @endforeach
                                </select>
                                <x-input-label for="deploy_target_linux_user_team" :value="__('System user')" />
                                <select id="deploy_target_linux_user_team" wire:model="deploy_target_linux_user" class="block w-full rounded-lg border border-brand-ink/15 px-3 py-2 text-sm">
                                    @foreach ($system_users as $u)
                                        <option value="{{ $u }}">{{ $u }}</option>
                                    @endforeach
                                </select>
                                <x-primary-button type="submit" class="!py-2" wire:loading.attr="disabled">
                                    <span wire:loading.remove wire:target="deployTeamKey">{{ __('Deploy team key') }}</span>
                                    <span wire:loading wire:target="deployTeamKey">{{ __('Deploying…') }}</span>
                                </x-primary-button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            <div class="{{ $card }} mt-6 overflow-hidden">
                <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-brand-ink/10 px-6 py-5 sm:px-8">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Keys on this server') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('Fingerprints, rotation dates, removal — applied on the next sync.') }}</p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-brand-sand/40 px-2.5 py-1 text-[11px] font-semibold text-brand-moss">
                        <span class="h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                        {{ trans_choice('{0} no keys|{1} :count key|[2,*] :count keys', $server->authorizedKeys->count(), ['count' => $server->authorizedKeys->count()]) }}
                    </span>
                </div>

                @if ($server->authorizedKeys->isEmpty())
                    <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
                        <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss">
                            <x-heroicon-o-key class="h-6 w-6" />
                        </span>
                        <p class="text-sm font-medium text-brand-ink">{{ __('No keys stored yet.') }}</p>
                        <p class="text-xs text-brand-moss">{{ __('Add or attach a key above, then sync to push it to the server.') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/8">
                        @foreach ($server->authorizedKeys->sortBy('name') as $ak)
                            @php
                                $effectiveUser = ($ak->target_linux_user ?? '') === '' ? $server->ssh_user : $ak->target_linux_user;
                                $fp = $fingerprints[$ak->id] ?? null;
                                $reviewAfter = data_get($ak, 'review_after');
                                $isOverdue = $reviewAfter && \Illuminate\Support\Carbon::parse($reviewAfter)->isPast();
                            @endphp
                            <li class="px-6 py-4 sm:px-8" wire:key="ak-{{ $ak->id }}">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                    <span class="mt-0.5 hidden h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-sand/30 text-brand-forest sm:inline-flex">
                                        <x-heroicon-o-key class="h-4.5 w-4.5" />
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="text-sm font-semibold text-brand-ink">{{ $ak->name }}</p>
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                                <x-heroicon-m-user class="h-3 w-3" />
                                                {{ $effectiveUser }}@if ($effectiveUser === $server->ssh_user) · {{ __('login') }}@endif
                                            </span>
                                            @if ($isOverdue)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-amber-800">
                                                    <x-heroicon-m-clock class="h-3 w-3" />
                                                    {{ __('Review due') }}
                                                </span>
                                            @endif
                                        </div>
                                        <p class="mt-0.5 text-[11px] text-brand-mist">
                                            {{ __('Added :time', ['time' => $ak->created_at?->diffForHumans() ?? '—']) }}
                                        </p>

                                        @if ($fp)
                                            <details class="mt-2">
                                                <summary class="cursor-pointer list-none text-[11px] font-medium uppercase tracking-wide text-brand-mist hover:text-brand-ink">
                                                    <span class="inline-flex items-center gap-1">
                                                        <x-heroicon-o-chevron-down class="h-3 w-3" />
                                                        {{ __('Fingerprints') }}
                                                    </span>
                                                </summary>
                                                <div class="mt-2 space-y-1 rounded-lg bg-brand-sand/15 px-3 py-2">
                                                    @if (! empty($fp['sha256']))
                                                        <p class="font-mono text-[11px] text-brand-ink"><span class="text-brand-mist">SHA256</span> {{ $fp['sha256'] }}</p>
                                                    @endif
                                                    @if (! empty($fp['md5']))
                                                        <p class="font-mono text-[11px] text-brand-moss"><span class="text-brand-mist">MD5&nbsp;&nbsp;&nbsp;</span> {{ $fp['md5'] }}</p>
                                                    @endif
                                                </div>
                                            </details>
                                        @endif
                                    </div>

                                    <div class="flex flex-wrap items-center gap-2 self-start sm:self-center">
                                        <div class="flex items-stretch overflow-hidden rounded-lg border border-brand-ink/15 bg-white">
                                            <span class="flex shrink-0 items-center bg-brand-sand/30 px-2 text-[10px] font-medium uppercase tracking-wide text-brand-mist">{{ __('Review') }}</span>
                                            <input
                                                id="rev-{{ $ak->id }}"
                                                type="date"
                                                wire:model="reviewDates.{{ $ak->id }}"
                                                class="block w-full border-0 bg-transparent px-2 py-1 text-xs text-brand-ink focus:outline-none focus:ring-0"
                                            />
                                            <button
                                                type="button"
                                                wire:click="updateKeyReviewFromInput('{{ $ak->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="updateKeyReviewFromInput('{{ $ak->id }}')"
                                                class="inline-flex shrink-0 items-center gap-1 border-l border-brand-ink/10 bg-brand-sand/15 px-2 text-[11px] font-semibold text-brand-ink hover:bg-brand-sand/40 disabled:opacity-50"
                                                title="{{ __('Save review-after date') }}"
                                            >
                                                <x-heroicon-m-check wire:loading.remove wire:target="updateKeyReviewFromInput('{{ $ak->id }}')" class="h-3 w-3" />
                                                <span wire:loading wire:target="updateKeyReviewFromInput('{{ $ak->id }}')"><x-spinner variant="forest" size="sm" /></span>
                                            </button>
                                        </div>
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('deleteAuthorizedKey', ['{{ $ak->id }}'], @js(__('Delete authorized key')), @js(__('Remove this key from the panel? Sync to apply on the server.')), @js(__('Delete key')), true)"
                                            wire:loading.attr="disabled"
                                            wire:target="deleteAuthorizedKey('{{ $ak->id }}')"
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:opacity-50"
                                            title="{{ __('Remove key') }}"
                                        >
                                            <x-heroicon-o-trash class="h-4 w-4" wire:loading.remove wire:target="deleteAuthorizedKey('{{ $ak->id }}')" />
                                            <span wire:loading wire:target="deleteAuthorizedKey('{{ $ak->id }}')"><x-spinner variant="forest" size="sm" /></span>
                                        </button>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <details class="{{ $card }} mt-6 group">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5 text-left sm:px-8">
                    <div>
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Recent audit history') }}</h2>
                        <p class="mt-1 text-sm text-brand-moss">{{ __('See who changed server SSH keys without giving audit its own full tab.') }}</p>
                    </div>
                    <span class="text-sm font-medium text-brand-sage group-open:hidden">{{ __('Show') }}</span>
                    <span class="hidden text-sm font-medium text-brand-sage group-open:inline">{{ __('Hide') }}</span>
                </summary>
                <div class="border-t border-brand-ink/10 px-6 py-6 sm:px-8">
                    <div class="overflow-x-auto rounded-xl border border-brand-ink/10">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="bg-brand-sand/30 text-left text-xs font-semibold uppercase text-brand-moss">
                                <tr>
                                    <th class="px-3 py-2">{{ __('When') }}</th>
                                    <th class="px-3 py-2">{{ __('Event') }}</th>
                                    <th class="px-3 py-2">{{ __('Actor') }}</th>
                                    <th class="px-3 py-2">{{ __('IP') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/10">
                                @forelse ($auditEvents as $ev)
                                    <tr>
                                        <td class="whitespace-nowrap px-3 py-2 text-xs text-brand-moss">{{ $ev->created_at?->format('Y-m-d H:i:s') }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $ev->event }}</td>
                                        <td class="px-3 py-2 text-xs">{{ $ev->user?->email ?? '—' }}</td>
                                        <td class="px-3 py-2 font-mono text-xs">{{ $ev->ip_address ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-3 py-6 text-center text-sm text-brand-moss">{{ __('No events yet.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </details>
        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="ssh-panel-preview"
            labelled-by="ssh-tab-preview"
            :hidden="$ssh_workspace_tab !== 'preview'"
        >
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Drift preview') }}</h2>
                    <button type="button" wire:click="previewDiff" wire:loading.attr="disabled" wire:target="previewDiff" class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm disabled:opacity-50">
                        <span wire:loading.remove wire:target="previewDiff">{{ __('Refresh preview') }}</span>
                        <span wire:loading wire:target="previewDiff" class="inline-flex items-center gap-2">
                            <x-spinner variant="forest" size="sm" />
                            {{ __('Refreshing…') }}
                        </span>
                    </button>
                </div>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Compares the panel’s desired keys with what is on the server now (read-only).') }}</p>
                @if ($diff_result === null)
                    <p class="mt-6 text-sm text-brand-moss">{{ __('Use “Review drift” from Keys or “Refresh preview” here to load the latest comparison.') }}</p>
                @else
                    <div class="mt-6 space-y-6">
                        @foreach ($diff_result as $user => $block)
                            <div class="rounded-xl border border-brand-ink/10 p-4">
                                <h3 class="font-semibold text-brand-ink">{{ $user }}</h3>
                                @if ($block['added'] !== [])
                                    <p class="mt-2 text-xs font-semibold uppercase text-emerald-800">{{ __('Would add') }}</p>
                                    <ul class="mt-1 list-inside list-disc font-mono text-xs text-brand-ink">
                                        @foreach ($block['added'] as $line)
                                            <li class="break-all">{{ \Illuminate\Support\Str::limit($line, 120) }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($block['removed'] !== [])
                                    <p class="mt-3 text-xs font-semibold uppercase text-red-800">{{ __('Would remove') }}</p>
                                    <ul class="mt-1 list-inside list-disc font-mono text-xs text-brand-ink">
                                        @foreach ($block['removed'] as $line)
                                            <li class="break-all">{{ \Illuminate\Support\Str::limit($line, 120) }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($block['added'] === [] && $block['removed'] === [])
                                    <p class="mt-2 text-sm text-brand-moss">{{ __('No drift for this user.') }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="ssh-panel-advanced"
            labelled-by="ssh-tab-advanced"
            :hidden="$ssh_workspace_tab !== 'advanced'"
        >
            <div class="{{ $card }} p-6 sm:p-8 space-y-6">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Advanced') }}</h2>
                <form wire:submit="saveAdvancedSettings" class="space-y-4 max-w-xl">
                    <div class="flex items-start gap-3">
                        <input id="adv_disable" type="checkbox" wire:model.boolean="advanced_disable_sync" class="mt-1 rounded border-brand-ink/20" />
                        <div>
                            <x-input-label for="adv_disable" :value="__('Disable authorized_keys sync (break-glass)')" class="!mb-0" />
                            <p class="text-xs text-brand-moss">{{ __('Blocks automated and dashboard writes until turned off.') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <input id="adv_health" type="checkbox" wire:model.boolean="advanced_health_check" class="mt-1 rounded border-brand-ink/20" />
                        <div>
                            <x-input-label for="adv_health" :value="__('Run sshd -t and stat after each sync')" class="!mb-0" />
                            <p class="text-xs text-brand-moss">{{ __('Uses root for sshd -t; deploy user for stat of ~/.ssh/authorized_keys.') }}</p>
                        </div>
                    </div>
                    <div>
                        <x-input-label for="adv_tpl" :value="__('Label template (optional)')" />
                        <x-text-input id="adv_tpl" wire:model="advanced_label_template" class="mt-1 block w-full" placeholder="{name} · {hostname} · {date}" />
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('Placeholders: :p.', ['p' => '{name}, {user}, {hostname}, {date}']) }}
                            {{ __('Organization default: set the ssh_key_label_template key in organization server site preferences; per-server meta overrides it.') }}
                        </p>
                    </div>
                    <x-primary-button type="submit" class="!py-2" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveAdvancedSettings">{{ __('Save advanced settings') }}</span>
                        <span wire:loading wire:target="saveAdvancedSettings">{{ __('Saving…') }}</span>
                    </x-primary-button>
                </form>
                <p class="text-xs text-brand-moss">{{ __('Outbound webhooks: configure the server “Outbound webhook” URL in Settings to receive JSON when sync completes (signed with your webhook secret).') }}</p>
            </div>
        </x-server-workspace-tab-panel>

        @include('livewire.partials.ssh-keypair-reveal-modal', ['revealContext' => 'server'])
        </div>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    <x-slot name="modals">
        <livewire:profile.personal-ssh-key-modal source="servers.workspace-ssh-keys" />
        @include('livewire.partials.confirm-action-modal')
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
