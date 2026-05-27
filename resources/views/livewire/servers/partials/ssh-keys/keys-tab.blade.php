            @php
                // Source mode is implicit: if a profile key is selected, we're in "profile" mode;
                // otherwise default to "paste" (the form fields). "Generate" is a one-shot button.
                $sourceMode = $profile_key_id ? 'profile' : 'paste';
                $hasProfile = $profileKeys->isNotEmpty();
            @endphp

            {{-- Slim trigger card. The "Add a key" button opens a modal containing the actual
                 Profile/Paste/Generate form — keeps the page from being dominated by a 165-line
                 form when the operator is just here to sync or review drift. --}}
            <div class="{{ $card }} overflow-hidden">
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6">
                        <div class="flex min-w-0 items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Authorized keys') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add an SSH key') }}</h3>
                                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                    {{ __('Authorize a key, then click Sync to push it to the server\'s authorized_keys.') }}
                                    <a href="https://www.ssh.com/academy/ssh/public-key-authentication" target="_blank" rel="noopener" class="whitespace-nowrap font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">{{ __('Learn more') }}</a>
                                </p>
                                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-brand-ink/10 bg-white px-2 py-0.5">
                                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                        <span class="font-mono tabular-nums text-brand-ink">{{ $trackedKeyCount }}</span>
                                        {{ trans_choice('{0} keys tracked|{1} key tracked|[2,*] keys tracked', $trackedKeyCount) }}
                                    </span>
                                    @if ($lastSyncFinishedAt && in_array($lastSyncStatus, ['completed', 'failed'], true))
                                        @if ($lastSyncStatus === 'completed')
                                            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-emerald-700">
                                                <x-heroicon-m-check-circle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('synced :time', ['time' => \Illuminate\Support\Carbon::parse($lastSyncFinishedAt)->diffForHumans()]) }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-rose-200 bg-rose-50 px-1.5 py-0.5 text-rose-700">
                                                <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                                                {{ __('last sync failed :time', ['time' => \Illuminate\Support\Carbon::parse($lastSyncFinishedAt)->diffForHumans()]) }}
                                            </span>
                                        @endif
                                    @else
                                        <span class="inline-flex items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-brand-moss">
                                            <x-heroicon-m-clock class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ __('not yet synced') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <button
                                type="button"
                                x-on:click="$dispatch('open-modal', 'add-ssh-key-modal')"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Add a key') }}
                            </button>
                            <button
                                type="button"
                                wire:click="requestSyncAuthorizedKeys"
                                wire:loading.attr="disabled"
                                wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys"
                                @disabled($syncBusy)
                                title="{{ $syncBusy ? __('A sync is already running. Wait for it to finish.') : '' }}"
                                class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <x-heroicon-m-arrow-path class="h-3.5 w-3.5 shrink-0" wire:loading.remove wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys" aria-hidden="true" />
                                <span wire:loading wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                    <x-spinner variant="forest" size="sm" />
                                </span>
                                <span wire:loading.remove wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys">{{ __('Sync now') }}</span>
                                <span wire:loading wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys">{{ __('Syncing…') }}</span>
                            </button>
                            <button type="button" wire:click="setSshWorkspaceTab('preview')" class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                                <x-heroicon-m-magnifying-glass class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Review drift') }}
                            </button>
                        </div>
                    </div>
                </div>

                @if (! $serverHasPersonalProfileKey)
                    <div class="mx-6 my-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3 text-sm text-amber-900 sm:mx-8">
                        <p class="min-w-0 leading-6">
                            <span class="font-semibold">{{ __('No personal key on this server yet.') }}</span>
                            {{ __('Add one to authorized_keys, then sync.') }}
                        </p>
                        @if ($profileKeys->isEmpty())
                            <button type="button" x-on:click="$dispatch('open-modal', 'personal-ssh-key-modal')" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                                <x-heroicon-o-plus class="h-3.5 w-3.5" />
                                {{ __('Add profile key') }}
                            </button>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Add SSH key modal — Profile / Paste / Generate sources, name + key + target user
                 + advanced rotation date. Closes on successful add (Livewire dispatches close-modal
                 from addAuthorizedKey). Generate-new opens the keypair-reveal modal on top. --}}
            <x-modal name="add-ssh-key-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
                <div class="border-b border-brand-ink/10 px-6 py-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Authorized key') }}</p>
                    <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add an SSH key') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-brand-moss">
                        {{ __('Pick a source, then save. Run "Sync now" afterwards to write the new authorized_keys to the server.') }}
                    </p>
                </div>

                <div class="px-6 py-6">
                    {{-- Source picker: Profile / Paste / Generate --}}
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

                    <form wire:submit="addAuthorizedKey" id="add-ssh-key-form" class="mt-5 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="new_auth_name" :value="__('Name')" />
                                <x-text-input id="new_auth_name" wire:model="new_auth_name" class="mt-1 block w-full" placeholder="{{ __('e.g. Work laptop') }}" :disabled="(bool) $profile_key_id" />
                                <x-input-error :messages="$errors->get('new_auth_name')" class="mt-1" />
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
                                <x-input-error :messages="$errors->get('new_target_linux_user')" class="mt-1" />
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
                            <x-input-error :messages="$errors->get('new_auth_key')" class="mt-1" />
                        </div>

                        <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                            <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-brand-mist">
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
                    </form>
                </div>

                <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
                    <p class="mr-auto text-xs text-brand-moss">{{ __('Saved here · written to the server only on sync.') }}</p>
                    <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
                    <x-primary-button type="submit" form="add-ssh-key-form" wire:loading.attr="disabled" wire:target="addAuthorizedKey">
                        <span wire:loading.remove wire:target="addAuthorizedKey">{{ __('Add SSH key') }}</span>
                        <span wire:loading wire:target="addAuthorizedKey">{{ __('Adding…') }}</span>
                    </x-primary-button>
                </div>
            </x-modal>

            @if ($orgKeys->isNotEmpty() || $teamKeys->isNotEmpty())
                <div class="{{ $card }} mt-6 p-6 sm:p-8">
                    <h2 class="text-base font-semibold text-brand-ink">{{ __('Organization & team keys') }}</h2>
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
                                <x-primary-button
                                    type="submit"
                                    class="!py-2"
                                    wire:loading.attr="disabled"
                                    :disabled="$syncBusy"
                                    :title="$syncBusy ? __('A sync is in flight — wait for it to finish before deploying another key.') : ''"
                                >
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
                                <x-primary-button
                                    type="submit"
                                    class="!py-2"
                                    wire:loading.attr="disabled"
                                    :disabled="$syncBusy"
                                    :title="$syncBusy ? __('A sync is in flight — wait for it to finish before deploying another key.') : ''"
                                >
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
                        <h2 class="text-base font-semibold text-brand-ink">{{ __('Keys on this server') }}</h2>
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
                                        @php
                                            $isLastLoginKey = $this->isLastLoginUserKey($ak);
                                        @endphp
                                        <button
                                            type="button"
                                            wire:click="openConfirmActionModal('deleteAuthorizedKey', ['{{ $ak->id }}'], @js(__('Delete authorized key')), @js(__('Remove this key from the panel? Sync to apply on the server.')), @js(__('Delete key')), true)"
                                            wire:loading.attr="disabled"
                                            wire:target="deleteAuthorizedKey('{{ $ak->id }}')"
                                            @disabled($isLastLoginKey)
                                            class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700 disabled:cursor-not-allowed disabled:opacity-40 disabled:hover:border-transparent disabled:hover:bg-transparent disabled:hover:text-brand-mist"
                                            title="{{ $isLastLoginKey ? __('This is the only key for the login user — Dply needs it to reach this server. Add another key targeting :user first.', ['user' => $server->ssh_user ?: 'login']) : __('Remove key') }}"
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

            @include('livewire.partials.ssh-keypair-reveal-modal', ['revealContext' => 'server'])
