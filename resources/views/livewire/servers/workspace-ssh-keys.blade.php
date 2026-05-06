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

    <x-explainer class="mb-4" tone="warn">
        <p>{{ __('Manages the authorized_keys file for the dply system user on this server. Dply tracks what should be there; "Sync" reconciles the file on the box to match. Anything in authorized_keys that\'s NOT tracked here gets removed when you sync.') }}</p>
        <p>{{ __('Drift preview shows what would change before you sync — the diff between the file currently on the server and what dply expects. The audit log records every sync with a hash of the key set so you can trace which key was added/removed when.') }}</p>
        <p>{{ __('Locking out the dply system user is a real risk if its key gets dropped from this list. Always keep at least one key for the dply user; the workspace warns if you\'re about to sync with no keys.') }}</p>
    </x-explainer>

    @if ($opsReady)
        <div class="space-y-6">

        @php
            $syncStatus = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_status_key'));
            $syncRunId = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_run_id_key'));
            $syncError = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_error_key'));
            $syncStartedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_started_at_key'));
            $syncFinishedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_finished_at_key'));
            $syncBusy = in_array($syncStatus, ['queued', 'running'], true);
            $syncShowBanner = $syncRunId !== '' && in_array($syncStatus, ['queued', 'running', 'completed', 'failed'], true);

            $driftStatus = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_status_key'));
            $driftRunId = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_run_id_key'));
            $driftError = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_error_key'));
            $driftStartedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_started_at_key'));
            $driftFinishedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_drift_finished_at_key'));
            $driftBusy = in_array($driftStatus, ['queued', 'running'], true);
            $driftShowBanner = $driftRunId !== '' && in_array($driftStatus, ['queued', 'running', 'completed', 'failed'], true);

            $panelShowBanner = ! empty($panel_event_lines);

            // Banner precedence: an in-flight run always wins over a settled banner so a fresh
            // drift click doesn't get hidden behind a lingering completed sync. Among settled
            // banners, the most-recently-started one wins (that's the run the operator was
            // last looking at). Panel events (add/delete) come last — they're inline feedback
            // that yields to anything more important. If only one exists, take it.
            if ($syncBusy) {
                $bannerKind = 'sync';
            } elseif ($driftBusy) {
                $bannerKind = 'drift';
            } elseif ($syncShowBanner && $driftShowBanner) {
                $bannerKind = (string) ($syncStartedAt ?? '') >= (string) ($driftStartedAt ?? '') ? 'sync' : 'drift';
            } elseif ($syncShowBanner) {
                $bannerKind = 'sync';
            } elseif ($driftShowBanner) {
                $bannerKind = 'drift';
            } elseif ($panelShowBanner) {
                $bannerKind = 'panel';
            } else {
                $bannerKind = null;
            }
            $bannerBusy = ($bannerKind === 'sync' && $syncBusy) || ($bannerKind === 'drift' && $driftBusy);
            $bannerOutput = match ($bannerKind) {
                'sync' => $syncShowBanner ? $this->syncOutputLines : [],
                'drift' => $diff_output,
                'panel' => $panel_event_lines,
                default => [],
            };
            $bannerStatus = match ($bannerKind) {
                'sync' => $syncStatus,
                'drift' => $driftStatus,
                'panel' => $panel_event_status, // 'completed' / 'failed', set by emitPanelEvent
                default => '',
            };
            $bannerMessage = match ($bannerKind) {
                'sync' => match ($syncStatus) {
                    'queued' => __('Sync queued — waiting for a worker to pick it up…'),
                    'running' => __('Syncing authorized_keys to :host …', ['host' => $server->getSshConnectionString()]),
                    'completed' => __('Sync complete — authorized_keys updated.'),
                    'failed' => __('Sync failed — authorized_keys was not fully updated.'),
                    default => '',
                },
                'drift' => match ($driftStatus) {
                    'queued' => __('Drift preview queued — waiting for a worker to pick it up…'),
                    'running' => __('Comparing authorized_keys against :host …', ['host' => $server->getSshConnectionString()]),
                    'completed' => __('Drift preview ready.'),
                    'failed' => __('Drift preview failed.'),
                    default => '',
                },
                'panel' => $panel_event_message,
                default => '',
            };
            $bannerDismissAction = match ($bannerKind) {
                'drift' => 'dismissDriftBanner',
                'panel' => 'dismissPanelBanner',
                default => 'dismissSyncBanner',
            };
            // Subtitle is computed in the workspace because it depends on per-kind state
            // (sync error string, finished_at, drift error, etc.) — the component itself stays
            // generic.
            $bannerSubtitle = $bannerBusy
                ? __('Refreshing every 4s · safe to leave this page — the job runs on the queue.')
                : match (true) {
                    $bannerKind === 'sync' && $syncStatus === 'failed' && $syncError !== '' => $syncError,
                    $bannerKind === 'sync' && $syncStatus === 'completed' && $syncFinishedAt
                        => __('Finished :time', ['time' => \Illuminate\Support\Carbon::parse($syncFinishedAt)->diffForHumans()]),
                    $bannerKind === 'drift' && $driftStatus === 'failed' && $driftError !== '' => $driftError,
                    $bannerKind === 'drift' && $driftStatus === 'completed'
                        => __('Compared the panel’s desired keys against the server. See the Drift tab for the structured diff.'),
                    $bannerKind === 'panel'
                        => __('The panel was updated. The server\'s authorized_keys file is unchanged until you Sync.'),
                    default => null,
                };
            $bannerDefaultExpanded = $bannerKind === 'drift' || $bannerKind === 'panel';
        @endphp

        @if ($bannerKind !== null)
            <x-workspace-console-banner
                :status="$bannerStatus"
                :message="$bannerMessage"
                :subtitle="$bannerSubtitle"
                :output="$bannerOutput"
                :busy="$bannerBusy"
                :dismiss-action="$bannerDismissAction"
                :poll-action="$bannerBusy ? 'pollSyncStatus' : null"
                poll-interval="4s"
                :default-expanded="$bannerDefaultExpanded"
            />
        @endif

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
                $hasProfile = $profileKeys->isNotEmpty();
            @endphp

            {{-- Slim trigger card. The "Add a key" button opens a modal containing the actual
                 Profile/Paste/Generate form — keeps the page from being dominated by a 165-line
                 form when the operator is just here to sync or review drift. --}}
            @php
                $trackedKeyCount = $server->authorizedKeys->count();
                $lastSyncFinishedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_finished_at_key'));
                $lastSyncStatus = (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_status_key'));
            @endphp
            <div class="{{ $card }} overflow-hidden">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                            <x-heroicon-o-key class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Add an SSH key') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                                {{ __('Authorize a key, then click Sync to push it to the server\'s authorized_keys.') }}
                                <a href="https://www.ssh.com/academy/ssh/public-key-authentication" target="_blank" rel="noopener" class="whitespace-nowrap font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">{{ __('Learn more') }}</a>
                            </p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                    {{ trans_choice('{0} no keys tracked|{1} :count key tracked|[2,*] :count keys tracked', $trackedKeyCount, ['count' => $trackedKeyCount]) }}
                                </span>
                                @if ($lastSyncFinishedAt && in_array($lastSyncStatus, ['completed', 'failed'], true))
                                    <span class="text-brand-mist/60">·</span>
                                    <span class="inline-flex items-center gap-1">
                                        @if ($lastSyncStatus === 'completed')
                                            <x-heroicon-o-check-circle class="h-3 w-3 text-emerald-600" />
                                            {{ __('synced :time', ['time' => \Illuminate\Support\Carbon::parse($lastSyncFinishedAt)->diffForHumans()]) }}
                                        @else
                                            <x-heroicon-o-exclamation-triangle class="h-3 w-3 text-rose-600" />
                                            {{ __('last sync failed :time', ['time' => \Illuminate\Support\Carbon::parse($lastSyncFinishedAt)->diffForHumans()]) }}
                                        @endif
                                    </span>
                                @else
                                    <span class="text-brand-mist/60">·</span>
                                    <span>{{ __('not yet synced') }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center gap-2">
                        <button
                            type="button"
                            x-on:click="$dispatch('open-modal', 'add-ssh-key-modal')"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90"
                        >
                            <x-heroicon-o-plus class="h-3.5 w-3.5" />
                            {{ __('Add a key') }}
                        </button>
                        <span class="hidden h-5 w-px bg-brand-ink/10 sm:block" aria-hidden="true"></span>
                        <button
                            type="button"
                            wire:click="requestSyncAuthorizedKeys"
                            wire:loading.attr="disabled"
                            wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys"
                            @disabled($syncBusy)
                            title="{{ $syncBusy ? __('A sync is already running. Wait for it to finish.') : '' }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys" />
                            <span wire:loading wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                <x-spinner variant="forest" size="sm" />
                            </span>
                            <span wire:loading.remove wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys">{{ __('Sync now') }}</span>
                            <span wire:loading wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys">{{ __('Syncing…') }}</span>
                        </button>
                        <button type="button" wire:click="$set('ssh_workspace_tab', 'preview')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                            <x-heroicon-o-magnifying-glass class="h-3.5 w-3.5" />
                            {{ __('Review drift') }}
                        </button>
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

            @php
                $auditEventCount = $auditEvents->count();
                $latestAuditAt = $auditEvents->first()?->created_at;
            @endphp
            <details class="{{ $card }} mt-6 group">
                <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-6 py-5 text-left sm:px-8">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="hidden h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-forest ring-1 ring-brand-ink/10 sm:inline-flex">
                            <x-heroicon-o-clock class="h-5 w-5" />
                        </span>
                        <div class="min-w-0">
                            <h2 class="text-base font-semibold text-brand-ink">{{ __('Recent audit history') }}</h2>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('See who changed server SSH keys without giving audit its own full tab.') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                                <span class="inline-flex items-center gap-1">
                                    <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                                    {{ trans_choice('{0} no events recorded|{1} :count event recorded|[2,*] :count events recorded', $auditEventCount, ['count' => $auditEventCount]) }}
                                </span>
                                @if ($latestAuditAt)
                                    <span class="text-brand-mist/60">·</span>
                                    <span>{{ __('latest :time', ['time' => $latestAuditAt->diffForHumans()]) }}</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-medium text-brand-ink shadow-sm group-hover:bg-brand-sand/40">
                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5 transition-transform group-open:rotate-180" />
                        <span class="group-open:hidden">{{ __('Show') }}</span>
                        <span class="hidden group-open:inline">{{ __('Hide') }}</span>
                    </span>
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
                                        <td class="whitespace-nowrap px-3 py-2 text-xs text-brand-moss">{{ \App\Support\Servers\ServerDateFormatter::format($ev->created_at, $server) ?? '—' }}</td>
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
                    <div class="flex flex-wrap gap-2">
                        <button
                            type="button"
                            wire:click="requestSyncAuthorizedKeys"
                            wire:loading.attr="disabled"
                            wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys"
                            @disabled($syncBusy || $driftBusy)
                            title="{{ $syncBusy ? __('A sync is already running.') : ($driftBusy ? __('A drift preview is running — wait for it to finish.') : __('Apply the pending changes by writing authorized_keys on the server.')) }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-forest/30 bg-brand-forest/10 px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm hover:bg-brand-forest/15 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-o-arrow-up-tray class="h-3.5 w-3.5" />
                            <span wire:loading.remove wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys">{{ __('Sync now') }}</span>
                            <span wire:loading wire:target="requestSyncAuthorizedKeys,syncAuthorizedKeys">{{ __('Syncing…') }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="previewDiff"
                            wire:loading.attr="disabled"
                            wire:target="previewDiff"
                            @disabled($syncBusy || $driftBusy)
                            title="{{ $syncBusy ? __('A sync is in flight — wait for it to finish before refreshing the drift preview.') : ($driftBusy ? __('A drift preview is already running. Wait for it to finish.') : '') }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            <x-heroicon-o-arrow-path class="h-3.5 w-3.5" wire:loading.remove wire:target="previewDiff" />
                            <span wire:loading wire:target="previewDiff" class="inline-flex h-3.5 w-3.5 items-center justify-center">
                                <x-spinner variant="forest" size="sm" />
                            </span>
                            <span wire:loading.remove wire:target="previewDiff">{{ __('Refresh preview') }}</span>
                            <span wire:loading wire:target="previewDiff">{{ __('Refreshing…') }}</span>
                        </button>
                    </div>
                </div>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Compares the panel’s desired keys with what is on the server now (read-only).') }}</p>
                <p class="mt-1 text-xs text-brand-mist">
                    {{ __('“Will add” / “Will remove” means: when you click Sync, the server\'s authorized_keys file will gain or lose that key. Adding a key in the panel doesn\'t touch the server — only Sync does.') }}
                </p>
                @if ($diff_result === null)
                    @php
                        $lastSyncFinishedAt = data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_finished_at_key'));
                        $recentlySynced = $lastSyncFinishedAt
                            && (string) data_get($server->meta ?? [], config('server_ssh_keys.meta_sync_status_key')) === 'completed';
                    @endphp
                    <div class="mt-6 flex flex-col items-center gap-2 rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/15 px-6 py-10 text-center">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-2xl bg-white text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-arrows-right-left class="h-5 w-5" />
                        </span>
                        @if ($recentlySynced)
                            <p class="text-sm font-medium text-brand-ink">{{ __('Sync finished — drift preview cleared.') }}</p>
                            <p class="text-xs text-brand-moss">{{ __('Click “Refresh preview” to confirm the server now matches the panel.') }}</p>
                        @else
                            <p class="text-sm font-medium text-brand-ink">{{ __('No comparison loaded yet.') }}</p>
                            <p class="text-xs text-brand-moss">{{ __('Click “Refresh preview” to compare the panel against authorized_keys on the server.') }}</p>
                        @endif
                    </div>
                @else
                    <div class="mt-6 space-y-4">
                        @php
                            // One-time helpers for the rendering loop. Pull a stable "type" out of an
                            // OpenSSH public key line so we can render it as a chip; the comment (last
                            // whitespace-separated token) becomes the human-readable label, and the
                            // middle base64 blob is what gets monospace-displayed.
                            $sshTypeOf = static function (string $line): string {
                                $tok = strtok(trim($line), ' ');

                                return is_string($tok) ? $tok : 'ssh-?';
                            };
                            $sshCommentOf = static function (string $line): string {
                                $parts = preg_split('/\s+/', trim($line)) ?: [];

                                return count($parts) >= 3 ? implode(' ', array_slice($parts, 2)) : '';
                            };
                            $sshBodyOf = static function (string $line): string {
                                $parts = preg_split('/\s+/', trim($line)) ?: [];

                                return $parts[1] ?? trim($line);
                            };
                            // Recognize Dply's auto-managed keys so we can flag them clearly in the
                            // "kept" list — they aren't in the panel, but they're always on the
                            // server because the synchronizer re-injects them on every sync.
                            $operationalKeyLine = trim((string) ($server->openSshPublicKeyFromOperationalPrivate() ?? ''));
                            $recoveryKeyLine = trim((string) ($server->openSshPublicKeyFromRecoveryPrivate() ?? ''));
                            $isManagedKey = static function (string $line) use ($operationalKeyLine, $recoveryKeyLine): ?string {
                                if ($operationalKeyLine !== '' && trim($line) === $operationalKeyLine) {
                                    return 'operational';
                                }
                                if ($recoveryKeyLine !== '' && trim($line) === $recoveryKeyLine) {
                                    return 'recovery';
                                }

                                return null;
                            };
                        @endphp
                        @foreach ($diff_result as $user => $block)
                            @php
                                // Hide the root user from the workspace diff. Dply auto-manages a
                                // recovery key under root and we don't want to advertise that
                                // surface in the UI — the operator can still observe drift via
                                // audit events / direct server access if they need to.
                                if ($user === 'root') {
                                    continue;
                                }
                                $addedCount = count($block['added']);
                                $removedCount = count($block['removed']);
                                $keptLines = $block['kept'] ?? [];
                                $keptCount = count($keptLines);
                                $hasDrift = $addedCount > 0 || $removedCount > 0;
                            @endphp
                            <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-brand-ink/8 bg-brand-sand/20 px-4 py-3 sm:px-5">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-white text-brand-forest ring-1 ring-brand-ink/10">
                                            <x-heroicon-m-user class="h-3.5 w-3.5" />
                                        </span>
                                        <h3 class="font-mono text-sm font-semibold text-brand-ink">{{ $user }}</h3>
                                        @if ($user === $server->ssh_user)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('login') }}</span>
                                        @endif
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2 text-[11px]">
                                        @if ($keptCount > 0)
                                            <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/40 px-2 py-0.5 font-semibold text-brand-moss ring-1 ring-brand-ink/10" title="{{ __('Already on the server — Sync would not change these.') }}">
                                                <x-heroicon-m-check class="h-3 w-3" />
                                                {{ trans_choice('{1} :count keeps|[2,*] :count keeps', $keptCount, ['count' => $keptCount]) }}
                                            </span>
                                        @endif
                                        @if ($hasDrift)
                                            @if ($addedCount > 0)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 ring-1 ring-emerald-200" title="{{ __('Will be added to authorized_keys on next sync') }}">
                                                    <x-heroicon-m-plus class="h-3 w-3" />
                                                    {{ trans_choice('{1} +:count to add|[2,*] +:count to add', $addedCount, ['count' => $addedCount]) }}
                                                </span>
                                            @endif
                                            @if ($removedCount > 0)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 font-semibold text-rose-700 ring-1 ring-rose-200" title="{{ __('Will be removed from authorized_keys on next sync') }}">
                                                    <x-heroicon-m-minus class="h-3 w-3" />
                                                    {{ trans_choice('{1} −:count to remove|[2,*] −:count to remove', $removedCount, ['count' => $removedCount]) }}
                                                </span>
                                            @endif
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 font-semibold text-emerald-700 ring-1 ring-emerald-200">
                                                <x-heroicon-m-check class="h-3 w-3" />
                                                {{ __('In sync') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <div>
                                    @if ($hasDrift)
                                        <div class="border-b border-brand-ink/8 bg-emerald-50/30 px-4 py-2 sm:px-5">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-emerald-900">
                                                {{ __('Pending changes — Sync will apply these to the server') }}
                                            </p>
                                        </div>
                                        <div class="divide-y divide-brand-ink/8">
                                            @foreach ($block['added'] as $line)
                                                <div class="flex items-start gap-3 px-4 py-2.5 sm:px-5">
                                                    <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200" title="{{ __('On next sync, this key will be written to authorized_keys on the server.') }}">
                                                        <x-heroicon-m-plus class="h-3 w-3" />
                                                    </span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="inline-flex items-center rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $sshTypeOf($line) }}</span>
                                                            @if (($comment = $sshCommentOf($line)) !== '')
                                                                <span class="text-xs font-medium text-brand-ink">{{ $comment }}</span>
                                                            @endif
                                                            <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800 ring-1 ring-emerald-200">{{ __('panel · not yet on server') }}</span>
                                                        </div>
                                                        <p class="mt-1 break-all font-mono text-[11px] leading-relaxed text-brand-mist" title="{{ $line }}">{{ \Illuminate\Support\Str::limit($sshBodyOf($line), 96) }}</p>
                                                    </div>
                                                </div>
                                            @endforeach
                                            @foreach ($block['removed'] as $line)
                                                <div class="flex items-start gap-3 px-4 py-2.5 sm:px-5">
                                                    <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-rose-50 text-rose-700 ring-1 ring-rose-200" title="{{ __('On next sync, this key will be removed from authorized_keys on the server.') }}">
                                                        <x-heroicon-m-minus class="h-3 w-3" />
                                                    </span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="inline-flex items-center rounded-md bg-brand-sand/40 px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $sshTypeOf($line) }}</span>
                                                            @if (($comment = $sshCommentOf($line)) !== '')
                                                                <span class="text-xs font-medium text-brand-ink">{{ $comment }}</span>
                                                            @else
                                                                <span class="text-xs italic text-brand-mist">{{ __('untracked key on server') }}</span>
                                                            @endif
                                                            <span class="inline-flex items-center gap-1 rounded-md bg-rose-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-rose-800 ring-1 ring-rose-200">{{ __('on server · not in panel') }}</span>
                                                        </div>
                                                        <p class="mt-1 break-all font-mono text-[11px] leading-relaxed text-brand-mist" title="{{ $line }}">{{ \Illuminate\Support\Str::limit($sshBodyOf($line), 96) }}</p>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if ($keptCount > 0)
                                        <div class="border-b border-t border-brand-ink/8 bg-brand-sand/15 px-4 py-2 sm:px-5">
                                            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-moss">
                                                {{ __('Already on the server — no change') }}
                                            </p>
                                        </div>
                                        <div class="divide-y divide-brand-ink/8 bg-brand-sand/10">
                                            @foreach ($keptLines as $line)
                                                @php $managedKind = $isManagedKey($line); @endphp
                                                <div class="flex items-start gap-3 px-4 py-2.5 sm:px-5">
                                                    <span class="mt-0.5 inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white text-brand-moss ring-1 ring-brand-ink/15" title="{{ __('Already on the server. Sync will not change this line.') }}">
                                                        <x-heroicon-m-check class="h-3 w-3" />
                                                    </span>
                                                    <div class="min-w-0 flex-1">
                                                        <div class="flex flex-wrap items-center gap-2">
                                                            <span class="inline-flex items-center rounded-md bg-white px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/10">{{ $sshTypeOf($line) }}</span>
                                                            @if ($managedKind === 'operational')
                                                                <span class="inline-flex items-center gap-1 rounded-md bg-sky-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                                                                    <x-heroicon-m-cog-6-tooth class="h-3 w-3" />
                                                                    {{ __('Dply operational') }}
                                                                </span>
                                                                <span class="text-xs text-brand-moss">{{ __('used by Dply to reach this server') }}</span>
                                                            @elseif (($comment = $sshCommentOf($line)) !== '')
                                                                <span class="text-xs font-medium text-brand-ink">{{ $comment }}</span>
                                                            @endif
                                                        </div>
                                                        <p class="mt-1 break-all font-mono text-[11px] leading-relaxed text-brand-mist" title="{{ $line }}">{{ \Illuminate\Support\Str::limit($sshBodyOf($line), 96) }}</p>
                                                    </div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if (! $hasDrift && $keptCount === 0)
                                        <div class="px-4 py-3 text-xs text-brand-moss sm:px-5">
                                            {{ __('No keys on the server for this user, and none in the panel either. Sync would write an empty authorized_keys.') }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- The drift transcript renders in the workspace-level "console" banner above
                     the tabs (same one the sync flow uses). Keeping the diff structure here on
                     the tab and routing the transcript through the shared banner makes the UX
                     consistent across both flows. --}}
            </div>
        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="ssh-panel-advanced"
            labelled-by="ssh-tab-advanced"
            :hidden="$ssh_workspace_tab !== 'advanced'"
        >
            <div class="{{ $card }} p-6 sm:p-8 space-y-6">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Advanced') }}</h2>
                <form wire:submit="requestSaveAdvancedSettings" class="space-y-4 max-w-xl">
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
                    <x-primary-button type="submit" class="!py-2" wire:loading.attr="disabled" wire:target="requestSaveAdvancedSettings,saveAdvancedSettings">
                        <span wire:loading.remove wire:target="requestSaveAdvancedSettings,saveAdvancedSettings">{{ __('Save advanced settings') }}</span>
                        <span wire:loading wire:target="requestSaveAdvancedSettings,saveAdvancedSettings">{{ __('Saving…') }}</span>
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
