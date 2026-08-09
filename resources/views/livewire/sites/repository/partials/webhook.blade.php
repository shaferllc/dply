@php
    $mode = $connectionQuickDeployMode ?? null;
    $isPoll = $mode === 'poll';
    $isWebhook = $mode === 'webhook';
    $pollCheckedAt = $connectionPollLastCheckedAt ?? null;
    $pollTip = $connectionPollLastTipSha ?? null;
    $pollLog = is_array($connectionPollLog ?? null) ? $connectionPollLog : [];
    $pollCheckedLabel = null;
    if (is_string($pollCheckedAt) && $pollCheckedAt !== '') {
        try {
            $pollCheckedLabel = \Illuminate\Support\Carbon::parse($pollCheckedAt)->diffForHumans();
        } catch (\Throwable) {
            $pollCheckedLabel = $pollCheckedAt;
        }
    }
@endphp
<section>
    <div class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            class="border-b border-brand-ink/10"
            icon="heroicon-o-bolt"
            :title="__('Quick deploy')"
            :note="__('Auto-deploy when new commits land. Choose webhook delivery (provider push) or poll delivery (dply checks Git on a short schedule).')"
        />

        <div class="px-5 py-4 sm:px-6 space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-2.5">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-brand-ink">
                        @if (! $connectionQuickDeploy)
                            {{ __('Quick deploy is disabled') }}
                        @elseif ($isPoll)
                            {{ __('Delivery: Poll') }}
                        @else
                            {{ __('Delivery: Webhook') }}
                        @endif
                    </p>
                    @if ($connectionQuickDeploy && $isPoll)
                        <p class="mt-1 text-[11px] text-brand-moss">
                            @if ($pollCheckedLabel)
                                {{ __('Last checked :when', ['when' => $pollCheckedLabel]) }}
                            @else
                                {{ __('Waiting for the next poll tick…') }}
                            @endif
                            @if ($pollTip)
                                <span class="font-mono text-brand-mist"> · {{ \Illuminate\Support\Str::substr($pollTip, 0, 7) }}</span>
                            @endif
                        </p>
                    @elseif ($connectionQuickDeploy && $isWebhook)
                        <p class="mt-1 text-[11px] text-brand-moss">
                            {{ __('Provider push hooks deploy this site automatically.') }}
                        </p>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($connectionQuickDeploy)
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('disableQuickDeploy', [], @js(__('Disable Quick deploy')), @js($isPoll ? __('Stop polling for new commits on this site?') : __('Disable Quick deploy and remove the provider push webhook?')), @js(__('Disable')), true)"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 shadow-sm hover:bg-rose-50"
                        >
                            <x-heroicon-o-x-mark class="h-4 w-4" />
                            {{ __('Disable') }}
                        </button>
                        @if ($isPoll)
                            <button
                                type="button"
                                wire:click="enableQuickDeploy"
                                wire:loading.attr="disabled"
                                wire:target="enableQuickDeploy"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                            >
                                {{ __('Use webhook delivery') }}
                            </button>
                        @else
                            <button
                                type="button"
                                wire:click="enableQuickDeployPoll"
                                wire:loading.attr="disabled"
                                wire:target="enableQuickDeployPoll"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                            >
                                {{ __('Use poll delivery') }}
                            </button>
                        @endif
                    @else
                        <button
                            type="button"
                            wire:click="enableQuickDeploy"
                            wire:loading.attr="disabled"
                            wire:target="enableQuickDeploy"
                            class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-bolt class="h-4 w-4" />
                            {{ __('Enable · Webhook') }}
                        </button>
                        <button
                            type="button"
                            wire:click="enableQuickDeployPoll"
                            wire:loading.attr="disabled"
                            wire:target="enableQuickDeployPoll"
                            class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:cursor-wait disabled:opacity-60"
                        >
                            <x-heroicon-o-arrow-path class="h-4 w-4" />
                            {{ __('Enable · Poll') }}
                        </button>
                    @endif
                    <button
                        type="button"
                        wire:click="openConfirmActionModal('regenerateWebhookSecret', [], @js(__('Rotate webhook secret')), @js(__('Rotate the webhook secret? Existing provider webhooks and signed hooks need the new secret.')), @js(__('Rotate')), true)"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-arrow-path class="h-4 w-4" />
                        {{ __('Rotate secret') }}
                    </button>
                </div>
            </div>

            @if ($connectionDeployHookUrl)
                <div class="rounded-lg border border-brand-ink/10 bg-white px-3 py-2">
                    <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Webhook URL') }}</p>
                    <p class="mt-1 break-all font-mono text-[11px] text-brand-moss">{{ $connectionDeployHookUrl }}</p>
                </div>
            @endif

            @if (! $connectionQuickDeploy || $isWebhook)
                <x-quick-deploy-oauth-hint :provider="$site->repositoryMeta()['git_provider_kind'] ?? 'custom'" class="text-[11px] leading-relaxed text-brand-mist" />
            @else
                <p class="text-[11px] leading-relaxed text-brand-mist">
                    {{ __('Poll delivery uses your linked source-control account to read the deploy branch tip every couple of minutes. No inbound provider webhook is required.') }}
                </p>
            @endif

            @if (($connectionQuickDeploy && $isPoll) || $pollLog !== [])
                <div class="border-t border-brand-ink/10 pt-3">
                    @include('livewire.sites.repository.partials.poll-log', [
                        'isPoll' => $connectionQuickDeploy && $isPoll,
                        'pollLog' => $pollLog,
                    ])
                </div>
            @endif

            <details class="group rounded-lg border border-brand-ink/10 bg-white">
                <summary class="cursor-pointer list-none px-3 py-2.5 text-xs font-semibold text-brand-ink marker:content-none [&::-webkit-details-marker]:hidden">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-chevron-right class="h-3.5 w-3.5 text-brand-mist transition-transform group-open:rotate-90" />
                        {{ __('Other ways to trigger a deploy') }}
                    </span>
                </summary>
                <ul class="space-y-2 border-t border-brand-ink/10 px-3 py-2.5 text-[11px] leading-relaxed text-brand-moss">
                    <li>
                        <span class="font-semibold text-brand-ink">{{ __('CLI') }}</span>
                        — <code class="font-mono text-brand-ink">dply deploy --follow</code>
                    </li>
                    <li>
                        <span class="font-semibold text-brand-ink">{{ __('API') }}</span>
                        — <code class="font-mono text-brand-ink">POST /api/v1/sites/{{ $site->id }}/deploy</code>
                    </li>
                    <li>
                        <span class="font-semibold text-brand-ink">{{ __('Signed hook') }}</span>
                        — {{ __('POST the webhook URL above with') }}
                        <code class="font-mono text-brand-ink">X-Dply-Signature</code>
                        {{ __('(same Rotate secret).') }}
                    </li>
                    <li>
                        <span class="font-semibold text-brand-ink">{{ __('Schedule') }}</span>
                        —
                        <a href="{{ route('sites.deployments.index', [$server, $site, 'tab' => 'schedule']) }}" wire:navigate class="font-semibold text-brand-forest underline-offset-2 hover:underline">
                            {{ __('Deployments → Schedule') }}
                        </a>
                    </li>
                    <li>
                        <span class="font-semibold text-brand-ink">{{ __('CI / CLI tokens') }}</span>
                        —
                        <a href="{{ route('profile.cli') }}" wire:navigate class="font-semibold text-brand-forest underline-offset-2 hover:underline">
                            {{ __('Profile → CLI') }}
                        </a>
                        {{ __('(includes a GitHub Actions snippet).') }}
                    </li>
                </ul>
            </details>
        </div>
    </div>
</section>
