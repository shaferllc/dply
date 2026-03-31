@php
    $card = 'rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden';
    $opsReady = $server->isReady() && $server->ssh_private_key;
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
                {{ __('Keys') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab id="ssh-tab-preview" :active="$ssh_workspace_tab === 'preview'" wire:click="$set('ssh_workspace_tab', 'preview')">
                {{ __('Preview') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab id="ssh-tab-audit" :active="$ssh_workspace_tab === 'audit'" wire:click="$set('ssh_workspace_tab', 'audit')">
                {{ __('Audit') }}
            </x-server-workspace-tab>
            <x-server-workspace-tab id="ssh-tab-advanced" :active="$ssh_workspace_tab === 'advanced'" wire:click="$set('ssh_workspace_tab', 'advanced')">
                {{ __('Advanced') }}
            </x-server-workspace-tab>
        </x-server-workspace-tablist>

        <x-server-workspace-tab-panel
            id="ssh-panel-keys"
            labelled-by="ssh-tab-keys"
            :hidden="$ssh_workspace_tab !== 'keys'"
            panel-class="space-y-6"
        >
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-brand-ink">{{ __('New SSH key') }}</h2>
                        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                            {{ __('Keys are written to that user’s ~/.ssh/authorized_keys on the server when you sync.') }}
                            <a href="https://www.ssh.com/academy/ssh/public-key-authentication" target="_blank" rel="noopener" class="font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage">{{ __('Learn more about SSH') }}</a>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="exportKeysCsv" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm">{{ __('Export CSV') }}</button>
                        <button type="button" wire:click="previewDiff" class="rounded-lg border border-brand-ink/15 bg-brand-sand/30 px-4 py-2 text-sm">{{ __('Dry run') }}</button>
                    </div>
                </div>

                @if ($profileKeys->isNotEmpty())
                    <div class="mt-6">
                        <x-input-label for="profile_key_id" :value="__('Select SSH key from your profile')" />
                        <select
                            id="profile_key_id"
                            wire:model.live="profile_key_id"
                            class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        >
                            <option value="">{{ __('Paste a new key below…') }}</option>
                            @foreach ($profileKeys as $pk)
                                <option value="{{ $pk->id }}">{{ $pk->name }}</option>
                            @endforeach
                        </select>
                        @if ($profile_key_id)
                            <p class="mt-2 text-xs text-brand-moss">
                                {{ __('Using your saved key content.') }}
                                <button type="button" wire:click="clearProfileSelection" class="font-medium text-brand-sage underline">{{ __('Clear') }}</button>
                            </p>
                        @endif
                    </div>
                    <div class="relative my-8">
                        <div class="absolute inset-0 flex items-center" aria-hidden="true">
                            <div class="w-full border-t border-brand-ink/10"></div>
                        </div>
                        <div class="relative flex justify-center">
                            <span class="bg-white px-3 text-xs font-medium uppercase tracking-wide text-brand-moss">{{ __('Or paste a new key') }}</span>
                        </div>
                    </div>
                @endif

                <form wire:submit="addAuthorizedKey" class="space-y-4">
                    <div>
                        <x-input-label for="new_auth_name" :value="__('Name')" />
                        <x-text-input id="new_auth_name" wire:model="new_auth_name" class="mt-1 block w-full" placeholder="{{ __('e.g. Work laptop') }}" :disabled="(bool) $profile_key_id" />
                    </div>
                    <div>
                        <x-input-label for="new_auth_key" :value="__('Public key')" />
                        <textarea
                            id="new_auth_key"
                            wire:model="new_auth_key"
                            rows="4"
                            @disabled($profile_key_id)
                            class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30 disabled:bg-brand-sand/50"
                            placeholder="ssh-ed25519 AAAA…"
                        ></textarea>
                    </div>
                    <div>
                        <x-input-label for="new_review_after" :value="__('Review after (optional)')" />
                        <x-text-input id="new_review_after" type="date" wire:model="new_review_after" class="mt-1 block w-full max-w-xs" />
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Used for rotation reminder emails to the server owner.') }}</p>
                    </div>
                    <div>
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="min-w-[12rem] flex-1">
                                <x-input-label for="new_target_linux_user" :value="__('System user')" />
                                <select
                                    id="new_target_linux_user"
                                    wire:model="new_target_linux_user"
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                                >
                                    @foreach ($system_users as $u)
                                        <option value="{{ $u }}">
                                            {{ $u }}
                                            @if ($u === $server->ssh_user)
                                                ({{ __('SSH login user') }})
                                            @endif
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="button" wire:click="loadSystemUsers" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/50">
                                {{ __('Load system users') }}
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('“Load system users” reads /etc/passwd over SSH. Writing keys for another user still needs passwordless sudo.') }}</p>
                    </div>
                    <div class="flex flex-wrap gap-2 pt-2">
                        <x-primary-button type="submit" class="!py-2">{{ __('Add SSH key') }}</x-primary-button>
                        <button type="button" wire:click="syncAuthorizedKeys" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm">
                            {{ __('Sync authorized_keys') }}
                        </button>
                    </div>
                </form>
            </div>

            @if ($orgKeys->isNotEmpty() || $teamKeys->isNotEmpty())
                <div class="{{ $card }} mt-6 p-6 sm:p-8">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Organization & team keys') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Attach an org or team key to this server and sync in one step.') }}</p>
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
                                <x-primary-button type="submit" class="!py-2">{{ __('Deploy org key') }}</x-primary-button>
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
                                <x-primary-button type="submit" class="!py-2">{{ __('Deploy team key') }}</x-primary-button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            <div class="{{ $card }} mt-6 p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Bulk import') }}</h2>
                <p class="mt-2 text-sm text-brand-moss">{{ __('One key per line:') }} <code class="rounded bg-brand-sand/60 px-1 text-xs">Name|system_user|ssh-…</code></p>
                <form wire:submit="bulkImportKeys" class="mt-4 space-y-3">
                    <textarea wire:model="bulk_import_text" rows="5" class="w-full rounded-lg border border-brand-ink/15 font-mono text-xs" placeholder="Laptop|dply|ssh-ed25519 AAAA…"></textarea>
                    <x-primary-button type="submit" class="!py-2">{{ __('Import rows') }}</x-primary-button>
                </form>
            </div>

            <div class="{{ $card }} mt-6 p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Keys on this server') }}</h2>
                @if ($server->authorizedKeys->isEmpty())
                    <p class="mt-3 text-sm text-brand-moss">{{ __('No keys stored yet.') }}</p>
                @else
                    <ul class="mt-4 divide-y divide-brand-ink/10">
                        @foreach ($server->authorizedKeys->sortBy('name') as $ak)
                            @php
                                $effectiveUser = ($ak->target_linux_user ?? '') === '' ? $server->ssh_user : $ak->target_linux_user;
                                $fp = $fingerprints[$ak->id] ?? null;
                            @endphp
                            <li class="py-4 first:pt-0">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium text-brand-ink">{{ $ak->name }}</p>
                                        <p class="mt-1 text-xs text-brand-moss">
                                            {{ $effectiveUser }} · {{ __('Added :time', ['time' => $ak->created_at?->format('Y-m-d H:i') ?? '—']) }}
                                        </p>
                                        @if ($fp)
                                            <p class="mt-1 font-mono text-[11px] text-brand-ink/80 break-all">{{ $fp['sha256'] ?? '—' }}</p>
                                            <p class="font-mono text-[11px] text-brand-moss break-all">{{ $fp['md5'] ?? '' }}</p>
                                        @endif
                                        <div class="mt-2 flex flex-wrap items-center gap-2">
                                            <label class="text-xs text-brand-moss" for="rev-{{ $ak->id }}">{{ __('Review after') }}</label>
                                            <input
                                                id="rev-{{ $ak->id }}"
                                                type="date"
                                                wire:model="reviewDates.{{ $ak->id }}"
                                                class="rounded border border-brand-ink/15 px-2 py-1 text-xs"
                                            />
                                            <button type="button" wire:click="updateKeyReviewFromInput('{{ $ak->id }}')" class="rounded border border-brand-ink/15 bg-white px-2 py-1 text-xs font-medium text-brand-ink hover:bg-brand-sand/50">
                                                {{ __('Save') }}
                                            </button>
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="deleteAuthorizedKey('{{ $ak->id }}')"
                                        wire:confirm="{{ __('Remove this key from the panel? Sync to apply on the server.') }}"
                                        class="shrink-0 rounded-lg p-2 text-red-600 hover:bg-red-50"
                                        title="{{ __('Remove') }}"
                                    >
                                        <x-heroicon-o-trash class="h-5 w-5" />
                                    </button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </x-server-workspace-tab-panel>

        <x-server-workspace-tab-panel
            id="ssh-panel-preview"
            labelled-by="ssh-tab-preview"
            :hidden="$ssh_workspace_tab !== 'preview'"
        >
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Drift preview') }}</h2>
                    <button type="button" wire:click="previewDiff" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm">{{ __('Refresh preview') }}</button>
                </div>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Compares the panel’s desired keys with what is on the server now (read-only).') }}</p>
                @if ($diff_result === null)
                    <p class="mt-6 text-sm text-brand-moss">{{ __('Click “Dry run” or “Refresh preview” to load.') }}</p>
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
            id="ssh-panel-audit"
            labelled-by="ssh-tab-audit"
            :hidden="$ssh_workspace_tab !== 'audit'"
        >
            <div class="{{ $card }} p-6 sm:p-8">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
                    <button type="button" wire:click="exportAuditCsv" class="rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm">{{ __('Export audit CSV') }}</button>
                </div>
                <div class="mt-6 overflow-x-auto rounded-xl border border-brand-ink/10">
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
                    <x-primary-button type="submit" class="!py-2">{{ __('Save advanced settings') }}</x-primary-button>
                </form>
                <p class="text-xs text-brand-moss">{{ __('Outbound webhooks: configure the server “Outbound webhook” URL in Settings to receive JSON when sync completes (signed with your webhook secret).') }}</p>
            </div>
        </x-server-workspace-tab-panel>
        </div>
    @else
        @include('livewire.servers.partials.workspace-ops-not-ready')
    @endif

    <x-slot name="modals">
        @include('livewire.servers.partials.remove-server-modal', [
            'open' => $showRemoveServerModal,
            'serverName' => $server->name,
            'serverId' => $server->id,
            'deletionSummary' => $deletionSummary,
        ])
    </x-slot>
</x-server-workspace-layout>
