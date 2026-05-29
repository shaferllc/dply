@php
    $card = 'dply-card overflow-hidden';
    $repoGroup = $repositorySyncGroup ?? null;
    $orgSites = $organizationSites ?? collect();
    $hasDeployKey = ! $server->hostCapabilities()->supportsFunctionDeploy() && $site->git_deploy_key_public;
    $providerLabel = match ($git_provider_kind) {
        'github' => 'GitHub',
        'gitlab' => 'GitLab',
        'bitbucket' => 'Bitbucket',
        default => __('Custom'),
    };
@endphp

<div class="space-y-6">
    {{-- Repository configuration: branch, provider, source-control account, URL. --}}
    <section class="{{ $card }}">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-code-bracket-square class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Repository') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Repository') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Branch, remote URL, and Git provider context. Changing the URL updates what Dply clones.') }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ $providerLabel }}
                        </span>
                        @if ($git_branch)
                            <span class="text-brand-mist/60">·</span>
                            <span class="inline-flex items-center gap-1">
                                <x-heroicon-m-arrow-path-rounded-square class="h-3 w-3" />
                                <span class="font-mono">{{ $git_branch }}</span>
                            </span>
                        @endif
                        @if ($git_repository_url)
                            <span class="text-brand-mist/60">·</span>
                            <span class="inline-flex items-center gap-1 truncate font-mono" title="{{ $git_repository_url }}">
                                <x-heroicon-m-link class="h-3 w-3" />
                                {{ \Illuminate\Support\Str::limit($git_repository_url, 48) }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-8">
            <div class="mb-5 rounded-xl border border-sky-200 bg-sky-50/70 px-4 py-3 text-sm text-sky-900">
                {{ __('Quick deploy registers a webhook with your provider. Re-link GitHub/GitLab/Bitbucket under Profile → Source control if the provider rejects the request.') }}
            </div>

            <form wire:submit="saveRepositoryWorkspace" id="save-repository-form" class="space-y-5">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="repo_git_branch" :value="__('Branch')" />
                        <x-text-input id="repo_git_branch" wire:model.blur="git_branch" class="mt-1 block w-full font-mono text-sm" />
                        <x-input-error :messages="$errors->get('git_branch')" class="mt-1" />
                    </div>

                    @if ($git_provider_kind !== 'custom')
                        <div>
                            <x-input-label for="git_source_control_account_id" :value="__('Source provider account')" />
                            <select id="git_source_control_account_id" wire:model="git_source_control_account_id" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                                <option value="">{{ __('Select a linked account') }}</option>
                                @foreach ($linkedSourceControlAccounts as $account)
                                    @if ($account['provider'] === $git_provider_kind)
                                        <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <x-input-error :messages="$errors->get('git_source_control_account_id')" class="mt-1" />
                            <p class="mt-1 text-xs text-brand-moss">{{ __('Only accounts matching the selected provider are listed.') }}</p>
                        </div>
                    @endif
                </div>

                <fieldset class="space-y-2">
                    <legend class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Repository type') }}</legend>
                    <div class="grid gap-2 sm:grid-cols-4">
                        @foreach (['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket', 'custom' => __('Custom')] as $k => $label)
                            <label class="cursor-pointer">
                                <input type="radio" wire:model.live="git_provider_kind" value="{{ $k }}" class="peer sr-only" />
                                <div class="rounded-xl border-2 px-4 py-2.5 text-center transition peer-checked:border-brand-sage peer-checked:bg-brand-sage/10 peer-focus:ring-2 peer-focus:ring-brand-sage/30 {{ $git_provider_kind === $k ? 'border-brand-sage bg-brand-sage/10' : 'border-brand-ink/12 bg-white hover:border-brand-ink/20' }}">
                                    <span class="text-sm font-semibold text-brand-ink">{{ $label }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                <div>
                    <x-input-label for="repo_git_repository_url" :value="__('Repository URL')" />
                    <x-text-input id="repo_git_repository_url" wire:model.blur="git_repository_url" class="mt-1 block w-full font-mono text-sm" placeholder="git@github.com:org/repo.git" />
                    <x-input-error :messages="$errors->get('git_repository_url')" class="mt-1" />
                </div>

                <label class="flex items-start gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="deploy_sync_include_peers_on_manual" class="mt-0.5 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30" />
                    <span>{{ __('When in a sync group, include peer sites when queueing a manual deploy from the site overview') }}</span>
                </label>
            </form>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:px-8">
            @if (! $server->hostCapabilities()->supportsFunctionDeploy())
                <button
                    type="button"
                    wire:click="generateDeployKey"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-key class="h-3.5 w-3.5" />
                    {{ __('Generate deploy key') }}
                </button>
            @endif
            <x-primary-button type="submit" form="save-repository-form">{{ __('Save repository') }}</x-primary-button>
        </div>

        @if ($hasDeployKey)
            <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-8">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-sm font-medium text-brand-ink">{{ __('Public deploy key') }}</p>
                    <span class="text-xs text-brand-mist">{{ __('Add to your Git provider') }}</span>
                </div>
                <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-3 font-mono text-[11px] leading-relaxed text-brand-ink">{{ $site->git_deploy_key_public }}</pre>
            </div>
        @endif
    </section>

    @feature('global.byo_repo_config')
        @if ($server->isVmHost())
            <livewire:sites.byo-repo-config-panel :site="$site" :key="'byo-repo-config-'.$site->id" />
        @endif
    @endfeature

    {{-- Quick deploy webhook toggle. --}}
    <section class="{{ $card }}">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Quick deploy') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Quick deploy') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Register a push webhook with your Git provider. Only the sync group leader registers an external webhook; peers deploy via coordination.') }}
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                        @if ($quick_deploy_enabled_ui)
                            <span class="inline-flex items-center gap-1">
                                <x-heroicon-o-check-circle class="h-3 w-3 text-emerald-600" />
                                <span class="text-emerald-700">{{ __('Enabled') }}</span>
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-mist"></span>
                                {{ __('Not enabled') }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                @if ($quick_deploy_enabled_ui)
                    <button
                        type="button"
                        wire:click="disableQuickDeploy"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-power class="h-3.5 w-3.5" />
                        {{ __('Disable Quick deploy') }}
                    </button>
                @else
                    <button
                        type="button"
                        wire:click="enableQuickDeploy"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90"
                    >
                        <x-heroicon-o-bolt class="h-3.5 w-3.5" />
                        {{ __('Enable Quick deploy') }}
                    </button>
                @endif
            </div>
        </div>
    </section>

    {{-- Synchronized deploy group. --}}
    <section class="{{ $card }}">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Sync group') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Synchronized deployments') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Group sites that share a repository so one push or coordinated manual deploy can update multiple destinations.') }}
                    </p>
                </div>
            </div>
            @if ($repoGroup)
                <span class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-full bg-brand-sand/40 px-2.5 py-1 text-[11px] font-semibold text-brand-moss">
                    <span class="h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                    {{ trans_choice('{1} :count site|[2,*] :count sites', $repoGroup->sites->count(), ['count' => $repoGroup->sites->count()]) }}
                </span>
            @endif
        </div>

        <div class="space-y-5 px-6 py-6 sm:px-8">
            @if ($repoGroup)
                <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-semibold text-brand-ink">{{ $repoGroup->name }}</p>
                        <p class="text-xs text-brand-moss">
                            {{ __('Leader: :site', ['site' => $repoGroup->leader?->name ?? __('Not set')]) }}
                        </p>
                    </div>
                    <ul class="mt-3 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10 bg-white">
                        @foreach ($repoGroup->sites as $gs)
                            <li class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                                <span class="text-brand-ink">{{ $gs->name }}</span>
                                @if ((string) $gs->id === (string) $repoGroup->leader_site_id)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                                        <x-heroicon-m-star class="h-3 w-3" />
                                        {{ __('leader') }}
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <form wire:submit="addSiteToDeploySyncGroup" class="space-y-3 rounded-xl border border-brand-ink/10 p-4">
                        <x-input-label for="sync_group_add_site" :value="__('Add site to group')" />
                        <select id="sync_group_add_site" wire:model="sync_group_add_site_id" class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                            <option value="">{{ __('Select site') }}</option>
                            @foreach ($orgSites as $os)
                                @if ((string) $os->id !== (string) $site->id && ! $repoGroup->sites->contains('id', $os->id))
                                    <option value="{{ $os->id }}">{{ $os->name }} ({{ $os->server?->name ?? __('Server') }})</option>
                                @endif
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('sync_group_add_site_id')" class="mt-1" />
                        <x-primary-button type="submit" class="!py-2">{{ __('Add to group') }}</x-primary-button>
                    </form>

                    <form wire:submit="setDeploySyncGroupLeader" class="space-y-3 rounded-xl border border-brand-ink/10 p-4">
                        <x-input-label for="sync_group_leader" :value="__('Leader site')" />
                        <select id="sync_group_leader" wire:model="sync_group_leader_site_id" class="block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30">
                            @foreach ($repoGroup->sites as $gs)
                                <option value="{{ $gs->id }}">{{ $gs->name }}</option>
                            @endforeach
                        </select>
                        <x-primary-button type="submit" class="!py-2">{{ __('Save leader') }}</x-primary-button>
                    </form>
                </div>

                <div>
                    <button
                        type="button"
                        wire:click="leaveDeploySyncGroup"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-transparent px-3 py-1.5 text-xs font-semibold text-rose-700 hover:border-rose-200 hover:bg-rose-50"
                    >
                        <x-heroicon-o-arrow-right-start-on-rectangle class="h-3.5 w-3.5" />
                        {{ __('Leave group') }}
                    </button>
                </div>
            @else
                <form wire:submit="createDeploySyncGroup" class="flex flex-wrap items-end gap-3">
                    <div class="min-w-[14rem] flex-1">
                        <x-input-label for="sync_group_name_input" :value="__('New group name')" />
                        <x-text-input id="sync_group_name_input" wire:model="sync_group_name_input" class="mt-1 block w-full" placeholder="production-fleet" />
                        <x-input-error :messages="$errors->get('sync_group_name_input')" class="mt-1" />
                    </div>
                    <x-primary-button type="submit">{{ __('Create group') }}</x-primary-button>
                </form>
            @endif
        </div>
    </section>

    {{-- After-deploy health pointer. --}}
    <section class="{{ $card }}">
        <div class="flex flex-col gap-4 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Health') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('After deploy health') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Atomic deploys can verify HTTP health before traffic switches. Failed deployments also trigger notification subscriptions when configured.') }}
                    </p>
                </div>
            </div>
            <a
                href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}"
                wire:navigate
                class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                <x-heroicon-o-arrow-top-right-on-square class="h-3.5 w-3.5" />
                {{ __('Configure in Deploy') }}
            </a>
        </div>
    </section>

    {{-- Inbound deploy webhook URL + secret rotation. --}}
    <section class="{{ $card }}">
        <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-arrow-down-on-square class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Webhook') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Inbound deploy webhook') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __('Providers send signed POST payloads (GitHub/GitLab) or use custom X-Dply-Signature. Optional IP allow list is under Notifications.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="space-y-4 px-6 py-6 sm:px-8">
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Webhook URL') }}</p>
                <div class="mt-2 flex items-stretch overflow-hidden rounded-lg border border-brand-ink/15 bg-white">
                    <p class="flex-1 break-all px-3 py-2 font-mono text-[11px] leading-relaxed text-brand-ink">{{ $deployHookUrl }}</p>
                    <button
                        type="button"
                        x-data="{ copied: false }"
                        x-on:click="navigator.clipboard.writeText(@js($deployHookUrl)); copied = true; setTimeout(() => copied = false, 2000)"
                        class="inline-flex shrink-0 items-center gap-1 border-l border-brand-ink/10 bg-brand-sand/15 px-3 text-[11px] font-semibold text-brand-ink hover:bg-brand-sand/40"
                    >
                        <x-heroicon-m-clipboard-document class="h-3.5 w-3.5" />
                        <span x-show="!copied">{{ __('Copy') }}</span>
                        <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                    </button>
                </div>
            </div>

            @if ($revealed_webhook_secret)
                <div class="rounded-xl border border-amber-200 bg-amber-50/70 p-4">
                    <p class="text-sm font-semibold text-amber-900">{{ __('Copy your new secret now:') }}</p>
                    <pre class="mt-2 overflow-x-auto rounded-lg border border-amber-200 bg-white p-3 font-mono text-[11px] leading-relaxed text-amber-900">{{ $revealed_webhook_secret }}</pre>
                </div>
            @else
                <p class="text-xs text-brand-moss">{{ __('Secret is stored encrypted. Rotate to update the provider hook when Quick deploy is enabled.') }}</p>
            @endif

            <div class="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    wire:click="regenerateWebhookSecret"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                    {{ __('Rotate webhook secret') }}
                </button>
                <a
                    href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'notifications']) }}"
                    wire:navigate
                    class="text-xs font-medium text-brand-sage underline decoration-brand-sage/30 hover:decoration-brand-sage"
                >
                    {{ __('IP allow list and notification subscriptions') }}
                </a>
            </div>
        </div>
    </section>

    {{-- Danger zone. --}}
    <section class="dply-card overflow-hidden border-rose-200">
        <div class="flex flex-col gap-4 border-b border-rose-200 bg-rose-50/60 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-rose-50 text-rose-700 ring-1 ring-rose-200">
                    <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-700">{{ __('Destructive') }}</p>
                    <h2 class="mt-0.5 text-lg font-semibold text-rose-900">{{ __('Danger zone') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-rose-900/80">
                        {{ __('Remove the deployed repository tree on the server without deleting this site in Dply.') }}
                    </p>
                </div>
            </div>
            <button
                type="button"
                wire:click="openConfirmActionModal('queueRemoveRemoteRepository', [], @js(__('Remove repository files?')), @js(__('This queues a job to delete the repository directory on the server. It does not delete the site record.')), @js(__('Remove repository')), true)"
                class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-lg border border-rose-300 bg-white px-3 py-1.5 text-xs font-semibold text-rose-800 shadow-sm hover:bg-rose-100"
            >
                <x-heroicon-o-trash class="h-3.5 w-3.5" />
                {{ __('Remove repository from server') }}
            </button>
        </div>
    </section>

    <x-cli-snippet :commands="[
        ['label' => __('Update remote / branch'), 'command' => 'dply:site:set-repo '.$site->slug.' --url=git@github.com:org/repo.git --branch=main'],
        ['label' => __('Switch monorepo path'), 'command' => 'dply:site:set-repo '.$site->slug.' --path=apps/web'],
        ['label' => __('Trigger redeploy after change'), 'command' => 'dply:site:deploy '.$site->slug],
    ]" />
</div>
