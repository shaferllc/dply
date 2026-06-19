<section class="space-y-6">
    <div class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-bolt class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Webhook') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Quick deploy') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('When enabled, dply registers a push webhook with your Git provider and queues a deploy on every push to the deploy branch.') }}
                </p>
            </div>
        </div>

        <div class="px-6 py-6 sm:px-7">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-brand-ink">
                        {{ $connectionQuickDeploy ? __('Quick deploy is enabled') : __('Quick deploy is disabled') }}
                    </p>
                    @if ($connectionDeployHookUrl)
                        <p class="mt-1 break-all font-mono text-[11px] text-brand-moss">{{ $connectionDeployHookUrl }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($connectionQuickDeploy)
                        <button
                            type="button"
                            wire:click="disableQuickDeploy"
                            wire:confirm="{{ __('Disable quick deploy and remove the provider webhook?') }}"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50"
                        >
                            <x-heroicon-o-x-mark class="h-4 w-4" />
                            {{ __('Disable') }}
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="enableQuickDeploy"
                            wire:loading.attr="disabled"
                            wire:target="enableQuickDeploy"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-bolt class="h-4 w-4" />
                            {{ __('Enable quick deploy') }}
                        </button>
                    @endif
                    <button
                        type="button"
                        wire:click="regenerateWebhookSecret"
                        wire:confirm="{{ __('Rotate the webhook secret? Existing webhooks need to be re-synced.') }}"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                        {{ __('Rotate secret') }}
                    </button>
                </div>
            </div>

            <x-quick-deploy-oauth-hint :provider="$site->repositoryMeta()['git_provider_kind'] ?? 'custom'" class="mt-3 text-[11px] leading-relaxed text-brand-mist" />
        </div>
    </div>
</section>
