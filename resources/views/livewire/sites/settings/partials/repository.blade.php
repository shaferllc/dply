@php
    $repoGroup = $repositorySyncGroup ?? null;
    $orgSites = $organizationSites ?? collect();
@endphp

<div class="space-y-8">
    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Repository') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('Branch, remote URL, and Git provider context. Changing the URL updates what Dply clones.') }}</p>
        </div>
        <div class="rounded-xl border border-sky-200 bg-sky-50 p-3 text-sm text-sky-950">
            {{ __('Quick deploy registers a webhook with your provider. Re-link GitHub/GitLab/Bitbucket under Profile → Source control if the provider rejects the request.') }}
        </div>
        <form wire:submit="saveRepositoryWorkspace" class="space-y-4">
            <div>
                <x-input-label for="repo_git_branch" value="{{ __('Branch') }}" />
                <x-text-input id="repo_git_branch" wire:model.blur="git_branch" class="mt-1 block w-full sm:w-64" />
                <x-input-error :messages="$errors->get('git_branch')" class="mt-1" />
            </div>
            <div>
                <span class="block text-sm font-medium text-slate-700">{{ __('Repository type') }}</span>
                <div class="mt-2 flex flex-wrap gap-4">
                    @foreach (['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket', 'custom' => __('Custom')] as $k => $label)
                        <label class="inline-flex items-center gap-2 text-sm text-slate-800">
                            <input type="radio" wire:model.live="git_provider_kind" value="{{ $k }}" class="rounded border-slate-300">
                            {{ $label }}
                        </label>
                    @endforeach
                </div>
            </div>
            @if ($git_provider_kind !== 'custom')
                <div>
                    <x-input-label for="git_source_control_account_id" value="{{ __('Source provider account') }}" />
                    <select id="git_source_control_account_id" wire:model="git_source_control_account_id" class="mt-1 block w-full max-w-lg rounded-md border-slate-300 text-sm shadow-sm">
                        <option value="">{{ __('Select a linked account') }}</option>
                        @foreach ($linkedSourceControlAccounts as $account)
                            @if ($account['provider'] === $git_provider_kind)
                                <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                            @endif
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('git_source_control_account_id')" class="mt-1" />
                    <p class="mt-1 text-xs text-slate-500">{{ __('Only accounts matching the selected provider are listed.') }}</p>
                </div>
            @endif
            <div>
                <x-input-label for="repo_git_repository_url" value="{{ __('Repository URL') }}" />
                <x-text-input id="repo_git_repository_url" wire:model.blur="git_repository_url" class="mt-1 block w-full font-mono text-sm" placeholder="git@github.com:org/repo.git" />
                <x-input-error :messages="$errors->get('git_repository_url')" class="mt-1" />
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-800">
                <input type="checkbox" wire:model="deploy_sync_include_peers_on_manual" class="rounded border-slate-300">
                {{ __('When in a sync group, include peer sites when queueing a manual deploy from the site overview') }}
            </label>
            <div class="flex flex-wrap gap-3">
                <x-primary-button type="submit">{{ __('Save repository') }}</x-primary-button>
                @if (! $server->hostCapabilities()->supportsFunctionDeploy())
                    <button type="button" wire:click="generateDeployKey" class="rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-900 shadow-sm hover:bg-slate-50">{{ __('Generate deploy key') }}</button>
                @endif
            </div>
        </form>
        @if (! $server->hostCapabilities()->supportsFunctionDeploy() && $site->git_deploy_key_public)
            <div>
                <p class="text-sm text-slate-600">{{ __('Public deploy key (add to your Git provider):') }}</p>
                <pre class="mt-2 overflow-x-auto rounded-xl bg-slate-900 p-3 text-xs text-green-400">{{ $site->git_deploy_key_public }}</pre>
            </div>
        @endif
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Quick deploy') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('Register a push webhook with your Git provider. Only the sync group leader registers an external webhook; peers deploy via coordination.') }}</p>
        </div>
        @if ($quick_deploy_enabled_ui)
            <p class="text-sm font-medium text-green-800">{{ __('Quick deploy is enabled.') }}</p>
            <button type="button" wire:click="disableQuickDeploy" class="rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800">{{ __('Disable Quick deploy') }}</button>
        @else
            <button type="button" wire:click="enableQuickDeploy" class="rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-sky-500">{{ __('Enable Quick deploy') }}</button>
        @endif
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">{{ __('Synchronized deployments') }}</h2>
                <p class="mt-1 text-sm text-slate-600 max-w-2xl">{{ __('Group sites that share a repository so one push or coordinated manual deploy can update multiple destinations.') }}</p>
            </div>
            @if ($repoGroup)
                <span class="text-sm font-medium text-slate-700">{{ __(':count sites', ['count' => $repoGroup->sites->count()]) }}</span>
            @endif
        </div>
        @if ($repoGroup)
            <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-800">
                <p class="font-medium">{{ $repoGroup->name }}</p>
                <p class="mt-1">{{ __('Leader: :site', ['site' => $repoGroup->leader?->name ?? __('Not set')]) }}</p>
                <ul class="mt-2 list-disc pl-5 space-y-1">
                    @foreach ($repoGroup->sites as $gs)
                        <li>{{ $gs->name }} @if ((string) $gs->id === (string) $repoGroup->leader_site_id)<span class="text-xs text-slate-500">({{ __('leader') }})</span>@endif</li>
                    @endforeach
                </ul>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="leaveDeploySyncGroup" class="text-sm font-medium text-red-700 hover:underline">{{ __('Leave group') }}</button>
            </div>
            <form wire:submit="addSiteToDeploySyncGroup" class="flex flex-wrap items-end gap-3 border-t border-slate-200 pt-4">
                <div class="min-w-[14rem] flex-1">
                    <x-input-label for="sync_group_add_site" value="{{ __('Add site') }}" />
                    <select id="sync_group_add_site" wire:model="sync_group_add_site_id" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                        <option value="">{{ __('Select site') }}</option>
                        @foreach ($orgSites as $os)
                            @if ((string) $os->id !== (string) $site->id && ! $repoGroup->sites->contains('id', $os->id))
                                <option value="{{ $os->id }}">{{ $os->name }} ({{ $os->server?->name ?? __('Server') }})</option>
                            @endif
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('sync_group_add_site_id')" class="mt-1" />
                </div>
                <x-primary-button type="submit">{{ __('Add to group') }}</x-primary-button>
            </form>
            <form wire:submit="setDeploySyncGroupLeader" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[14rem] flex-1">
                    <x-input-label for="sync_group_leader" value="{{ __('Leader site') }}" />
                    <select id="sync_group_leader" wire:model="sync_group_leader_site_id" class="mt-1 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                        @foreach ($repoGroup->sites as $gs)
                            <option value="{{ $gs->id }}">{{ $gs->name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-primary-button type="submit">{{ __('Save leader') }}</x-primary-button>
            </form>
        @else
            <form wire:submit="createDeploySyncGroup" class="flex flex-wrap items-end gap-3">
                <div class="min-w-[14rem] flex-1">
                    <x-input-label for="sync_group_name_input" value="{{ __('New group name') }}" />
                    <x-text-input id="sync_group_name_input" wire:model="sync_group_name_input" class="mt-1 block w-full" placeholder="production-fleet" />
                    <x-input-error :messages="$errors->get('sync_group_name_input')" class="mt-1" />
                </div>
                <x-primary-button type="submit">{{ __('Create group') }}</x-primary-button>
            </form>
        @endif
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-3">
        <h2 class="text-lg font-semibold text-slate-900">{{ __('After deploy health') }}</h2>
        <p class="text-sm text-slate-600">{{ __('Atomic deploys can verify HTTP health before traffic switches. Failed deployments also trigger notification subscriptions when configured.') }}</p>
        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}" wire:navigate class="text-sm font-medium text-sky-700 hover:underline">{{ __('Configure in Deploy → After deploy verification') }}</a>
    </section>

    <section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-4">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ __('Inbound deploy webhook') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('Providers send signed POST payloads (GitHub/GitLab) or use custom X-Dply-Signature. Optional IP allow list is under Notifications.') }}</p>
        </div>
        <div class="flex flex-wrap items-start gap-2">
            <p class="flex-1 rounded-xl bg-slate-50 p-3 font-mono text-xs break-all text-slate-900">{{ $deployHookUrl }}</p>
            <button type="button" x-data="{ copied: false }" x-on:click="navigator.clipboard.writeText(@js($deployHookUrl)); copied = true; setTimeout(() => copied = false, 2000)" class="shrink-0 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 shadow-sm hover:bg-slate-50">
                <span x-show="!copied">{{ __('Copy') }}</span>
                <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
            </button>
        </div>
        @if ($revealed_webhook_secret)
            <div>
                <p class="text-sm font-medium text-amber-800">{{ __('Copy your new secret now:') }}</p>
                <pre class="mt-2 overflow-x-auto rounded-xl bg-slate-900 p-3 text-xs text-amber-200">{{ $revealed_webhook_secret }}</pre>
            </div>
        @else
            <p class="text-sm text-slate-600">{{ __('Secret is stored encrypted. Rotate to update the provider hook when Quick deploy is enabled.') }}</p>
        @endif
        <button type="button" wire:click="regenerateWebhookSecret" class="text-sm font-medium text-slate-900 underline">{{ __('Rotate webhook secret') }}</button>
        <p class="text-xs text-slate-500">
            <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'notifications']) }}" wire:navigate class="font-medium text-sky-700 hover:underline">{{ __('IP allow list and notification subscriptions') }}</a>
        </p>
    </section>

    <section class="rounded-2xl border border-amber-200 bg-amber-50/60 p-6 shadow-sm sm:p-8 space-y-3">
        <h2 class="text-lg font-semibold text-amber-950">{{ __('Danger zone') }}</h2>
        <p class="text-sm text-amber-950">{{ __('Remove the deployed repository tree on the server without deleting this site in Dply.') }}</p>
        <button
            type="button"
            wire:click="openConfirmActionModal('queueRemoveRemoteRepository', [], @js(__('Remove repository files?')), @js(__('This queues a job to delete the repository directory on the server. It does not delete the site record.')), @js(__('Remove repository')), true)"
            class="rounded-xl border border-red-300 bg-red-50 px-4 py-2.5 text-sm font-medium text-red-800 hover:bg-red-100"
        >
            {{ __('Remove repository from server') }}
        </button>
    </section>
</div>
