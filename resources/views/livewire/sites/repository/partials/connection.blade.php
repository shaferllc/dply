<section class="space-y-6">
    <div class="dply-card p-6 sm:p-8">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Connection') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('Which OAuth account dply uses to read commits, list branches, and provision webhooks.') }}
        </p>

        <form wire:submit.prevent="saveConnection" class="mt-5 space-y-4">
            <label class="block text-sm">
                <span class="flex items-center justify-between gap-2">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Linked source-control account') }}</span>
                    <x-connect-provider-link>{{ __('Connect a provider') }} &rarr;</x-connect-provider-link>
                </span>
                <select
                    wire:model.live="connectionAccountId"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                >
                    <option value="">{{ __('— Custom / no linked account —') }}</option>
                    @foreach ($connectionAccounts as $account)
                        <option value="{{ $account['id'] }}">{{ $account['label'] }} ({{ $account['provider'] }})</option>
                    @endforeach
                </select>
            </label>

            <div class="grid gap-3 sm:grid-cols-2">
                <label class="block text-sm">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Repository URL') }}</span>
                    <input
                        type="text"
                        wire:model="connectionRepositoryUrl"
                        placeholder="git@github.com:acme/api.git"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                    />
                </label>
                <label class="block text-sm">
                    <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Deploy branch') }}</span>
                    <input
                        type="text"
                        wire:model="connectionBranch"
                        placeholder="main"
                        class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-ink focus:ring-1 focus:ring-brand-ink"
                    />
                </label>
            </div>

            <div class="flex justify-end">
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="saveConnection"
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                >
                    <x-heroicon-o-check class="h-4 w-4" />
                    <span wire:loading.remove wire:target="saveConnection">{{ __('Save connection') }}</span>
                    <span wire:loading wire:target="saveConnection">{{ __('Saving…') }}</span>
                </button>
            </div>
        </form>
    </div>

    @if (! empty($connectionRepositories))
        <div class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm">
            <header>
                <h2 class="text-base font-semibold text-brand-ink">{{ __('Repositories on this account') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('One-click swap to a different repository under the linked account. The deploy branch resets to the target repo\'s default.') }}</p>
            </header>
            <ul class="mt-4 divide-y divide-brand-ink/10">
                @foreach ($connectionRepositories as $repo)
                    @php($isCurrent = (string) ($repo['url'] ?? '') === $currentRepositoryUrl)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2" wire:key="repo-{{ $repo['url'] }}">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm text-brand-ink">{{ $repo['label'] }}</p>
                            @if (! empty($repo['url']))
                                <p class="mt-0.5 truncate text-[11px] text-brand-mist">{{ $repo['url'] }}</p>
                            @endif
                        </div>
                        @if ($isCurrent)
                            <span class="inline-flex items-center rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em] text-emerald-900">{{ __('current') }}</span>
                        @else
                            <button
                                type="button"
                                wire:click="switchRepository('{{ addslashes($repo['url']) }}', '{{ addslashes($repo['branch'] ?? 'main') }}')"
                                wire:confirm="{{ __('Switch this app to :label? Deploy branch will reset to :branch.', ['label' => $repo['label'], 'branch' => $repo['branch'] ?? 'main']) }}"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-arrow-right class="h-3.5 w-3.5" />
                                {{ __('Switch to this repo') }}
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="dply-card p-6 sm:p-8">
        <h2 class="text-base font-semibold text-brand-ink">{{ __('Quick deploy webhook') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">
            {{ __('When enabled, dply registers a push webhook with your Git provider and queues a deploy on every push to the deploy branch.') }}
        </p>

        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
            <div>
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
                        <x-heroicon-o-x-mark class="h-3.5 w-3.5" />
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
                        <x-heroicon-o-bolt class="h-3.5 w-3.5" />
                        {{ __('Enable quick deploy') }}
                    </button>
                @endif
                <button
                    type="button"
                    wire:click="regenerateWebhookSecret"
                    wire:confirm="{{ __('Rotate the webhook secret? Existing webhooks need to be re-synced.') }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-arrow-path class="h-3.5 w-3.5" />
                    {{ __('Rotate secret') }}
                </button>
            </div>
        </div>
    </div>

    <x-connect-provider-modal />
</section>
