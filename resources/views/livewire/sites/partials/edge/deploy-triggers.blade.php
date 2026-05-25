@if (! $site->isEdgePreview())
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('Deploy hooks') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Per-site URLs that trigger a redeploy when POSTed. Hand the URL to your CMS (Sanity, Contentful, Strapi) so a content change publishes automatically.') }}</p>
        </div>

        @if ($edge_just_minted_deploy_hook_url !== null)
            <div class="border-b border-emerald-300/60 bg-emerald-50 px-6 py-4 text-sm text-emerald-950 dark:border-emerald-900/40 dark:bg-emerald-950/30 dark:text-emerald-100 sm:px-8">
                <p class="font-semibold">{{ __('Copy your hook URL now — dply won\'t show it again') }}</p>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <code class="flex-1 min-w-0 break-all rounded-lg bg-white px-3 py-2 font-mono text-[11px] text-brand-ink shadow-sm dark:bg-zinc-900">{{ $edge_just_minted_deploy_hook_url }}</code>
                    <button type="button" wire:click="dismissEdgeDeployHookUrl" class="text-[11px] font-semibold text-emerald-900 hover:underline dark:text-emerald-200">{{ __('Got it') }}</button>
                </div>
            </div>
        @endif

        @can('update', $site)
            <form wire:submit.prevent="mintEdgeDeployHook" class="flex flex-wrap items-end gap-2 border-b border-brand-ink/10 px-6 py-4 sm:px-8">
                <label class="flex-1 min-w-[14rem]">
                    <span class="block text-[11px] font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Hook name') }}</span>
                    <input type="text"
                           wire:model="edge_new_deploy_hook_name"
                           placeholder="Sanity prod publish"
                           class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-1 focus:ring-brand-sage dark:border-brand-mist/20 dark:bg-zinc-900" />
                </label>
                <button type="submit" wire:loading.attr="disabled" wire:target="mintEdgeDeployHook" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                    {{ __('Create hook') }}
                </button>
            </form>
        @endcan

        @php
            $hooks = $this->edgeDeployHooks();
        @endphp
        @if ($hooks->isEmpty())
            <div class="px-6 py-6 text-center text-xs text-brand-moss sm:px-8">{{ __('No deploy hooks yet.') }}</div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($hooks as $hook)
                    <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 sm:px-8" wire:key="edge-hook-{{ $hook->id }}">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-brand-ink">{{ $hook->name }}</p>
                            <p class="mt-0.5 font-mono text-[11px] text-brand-moss">
                                {{ __('Token starts with') }} <span class="rounded-md bg-brand-sand/40 px-1.5 py-0.5 text-brand-ink">{{ $hook->token_prefix }}…</span>
                                @if ($hook->last_used_at)
                                    · {{ __('last fired :when', ['when' => $hook->last_used_at->diffForHumans()]) }}
                                @else
                                    · {{ __('never fired') }}
                                @endif
                            </p>
                        </div>
                        @can('update', $site)
                            <button
                                type="button"
                                wire:click="revokeEdgeDeployHook('{{ $hook->id }}')"
                                wire:confirm="{{ __('Revoke this deploy hook? The URL will stop working immediately.') }}"
                                class="text-xs font-medium text-rose-700 hover:text-rose-900 dark:text-rose-400">
                                {{ __('Revoke') }}
                            </button>
                        @endcan
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif

@if (! $edgeIsPreviewChild)
    <section class="dply-card overflow-hidden">
        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
            <h3 class="text-base font-semibold text-brand-ink">{{ __('GitHub auto-deploy') }}</h3>
            <p class="mt-0.5 text-sm text-brand-moss">{{ __('Connect a linked GitHub account to register push and pull request webhooks automatically. Pull requests get a Check Run and a single summary comment (updated in place on each push) with the preview URL when the deploy lands.') }}</p>
        </div>
        <div class="space-y-4 px-6 py-5 sm:px-8">
            <label class="block text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Linked GitHub account') }}</span>
                <select
                    wire:model.live="buildForm.edge_webhook_account_id"
                    class="mt-1.5 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink dark:border-brand-mist/20 dark:bg-zinc-900"
                >
                    <option value="">{{ __('Select a linked GitHub account…') }}</option>
                    @foreach (($linkedSourceControlAccounts ?? []) as $account)
                        @if (($account['provider'] ?? '') === 'github')
                            <option value="{{ $account['id'] }}">{{ $account['label'] }}</option>
                        @endif
                    @endforeach
                </select>
            </label>

            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
                <div>
                    <p class="text-sm font-semibold text-brand-ink">
                        {{ $edgeGithubWebhookConnected ? __('Auto-deploy is connected') : __('Auto-deploy is not connected') }}
                    </p>
                    <p class="mt-1 break-all font-mono text-[11px] text-brand-moss">{{ $site->edgeGithubHookUrl() }}</p>
                    @if ($edgeWebhookLastEventAt)
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Last webhook event: :time', ['time' => $edgeWebhookLastEventAt]) }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($edgeGithubWebhookConnected)
                        <button
                            type="button"
                            wire:click="disableEdgeGithubWebhook"
                            wire:loading.attr="disabled"
                            wire:target="disableEdgeGithubWebhook"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50 dark:border-rose-900/40 dark:bg-zinc-900 dark:text-rose-300"
                        >
                            <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
                            {{ __('Disable') }}
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="enableEdgeGithubWebhook"
                            wire:loading.attr="disabled"
                            wire:target="enableEdgeGithubWebhook"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-bolt class="h-3.5 w-3.5" />
                            {{ __('Enable auto-deploy') }}
                        </button>
                    @endif
                </div>
            </div>

            <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3 text-sm dark:border-brand-mist/20 dark:bg-zinc-900/40">
                <summary class="cursor-pointer font-medium text-brand-ink">{{ __('Manual webhook setup') }}</summary>
                <div class="mt-3 space-y-3" x-data="{ copiedHook: false, copiedSecret: false }">
                    <div>
                        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Payload URL') }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <input type="text" readonly value="{{ $site->edgeGithubHookUrl() }}" class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink" onclick="this.select()" />
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                                @click="navigator.clipboard.writeText(@js($site->edgeGithubHookUrl())); copiedHook = true; setTimeout(() => copiedHook = false, 2000)"
                            >
                                <x-heroicon-o-clipboard class="h-4 w-4" />
                                <span x-show="!copiedHook">{{ __('Copy') }}</span>
                                <span x-show="copiedHook" x-cloak>{{ __('Copied') }}</span>
                            </button>
                        </div>
                    </div>
                    @if ($site->webhook_secret)
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Webhook secret') }}</p>
                            <div class="mt-2 flex flex-wrap items-center gap-2">
                                <input type="password" readonly value="{{ $site->webhook_secret }}" class="block min-w-0 flex-1 rounded-xl border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink" onclick="this.select()" />
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/10 bg-white px-3 py-2 text-xs font-medium text-brand-moss hover:bg-brand-sand/40"
                                    @click="navigator.clipboard.writeText(@js($site->webhook_secret)); copiedSecret = true; setTimeout(() => copiedSecret = false, 2000)"
                                >
                                    <x-heroicon-o-clipboard class="h-4 w-4" />
                                    <span x-show="!copiedSecret">{{ __('Copy') }}</span>
                                    <span x-show="copiedSecret" x-cloak>{{ __('Copied') }}</span>
                                </button>
                            </div>
                        </div>
                    @endif
                </div>
            </details>
        </div>
    </section>
@endif
