<div class="space-y-6">
    <section class="dply-card overflow-hidden">
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-bell-alert class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Alerts') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('RUM alert thresholds') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Checked hourly against the last 60 minutes of traffic + vitals. Breaches publish the `edge.rum.breach` event; route it to Slack/email/webhook via your notification channels. Each (site, kind) has a 6-hour cooldown so sustained issues only page once per window.') }}
                </p>
            </div>
            <span wire:loading.inline-flex wire:target="lcp_enabled,err_rate_enabled,err_count_enabled,save" class="ml-auto inline-flex shrink-0 items-center gap-1.5 text-[11px] text-brand-moss">
                <x-spinner size="sm" variant="muted" />
                {{ __('Updating…') }}
            </span>
        </div>
        <div class="divide-y divide-brand-ink/10">
            <div class="grid grid-cols-1 gap-3 px-6 py-4 sm:grid-cols-[1fr_10rem_auto] sm:items-center sm:px-8">
                <div>
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model.live="lcp_enabled" class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                        <span class="text-sm">
                            <span class="font-semibold text-brand-ink">{{ __('LCP p75') }}</span>
                            <span class="block text-xs text-brand-moss">{{ __('Largest Contentful Paint, 75th percentile across collected RUM beacons.') }}</span>
                        </span>
                    </label>
                </div>
                <div>
                    <label class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist" for="lcp">{{ __('Threshold (ms)') }}</label>
                    <input id="lcp" type="number" min="100" max="60000" step="50" wire:model="lcp_threshold" wire:key="lcp-threshold-{{ $lcp_enabled ? 'on' : 'off' }}" @disabled(! $lcp_enabled) class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest disabled:bg-brand-sand/20" />
                    @error('lcp_threshold') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="text-[11px] text-brand-mist">{{ __('Industry "good": ≤2500ms') }}</div>
            </div>

            <div class="grid grid-cols-1 gap-3 px-6 py-4 sm:grid-cols-[1fr_10rem_auto] sm:items-center sm:px-8">
                <div>
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model.live="err_rate_enabled" class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                        <span class="text-sm">
                            <span class="font-semibold text-brand-ink">{{ __('5xx error rate') }}</span>
                            <span class="block text-xs text-brand-moss">{{ __('Percent of requests in the last hour returning HTTP 5xx.') }}</span>
                        </span>
                    </label>
                </div>
                <div>
                    <label class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist" for="errrate">{{ __('Threshold (%)') }}</label>
                    <input id="errrate" type="number" min="0.1" max="100" step="0.1" wire:model="err_rate_threshold" wire:key="err-rate-threshold-{{ $err_rate_enabled ? 'on' : 'off' }}" @disabled(! $err_rate_enabled) class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest disabled:bg-brand-sand/20" />
                    @error('err_rate_threshold') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="text-[11px] text-brand-mist">{{ __('Healthy: under 1%') }}</div>
            </div>

            <div class="grid grid-cols-1 gap-3 px-6 py-4 sm:grid-cols-[1fr_10rem_auto] sm:items-center sm:px-8">
                <div>
                    <label class="flex items-start gap-3">
                        <input type="checkbox" wire:model.live="err_count_enabled" class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                        <span class="text-sm">
                            <span class="font-semibold text-brand-ink">{{ __('5xx response count') }}</span>
                            <span class="block text-xs text-brand-moss">{{ __('Absolute count of 5xx responses in the last hour. Useful for sites with steady traffic where a small percentage is still meaningful.') }}</span>
                        </span>
                    </label>
                </div>
                <div>
                    <label class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist" for="errcnt">{{ __('Threshold') }}</label>
                    <input id="errcnt" type="number" min="1" max="1000000" step="1" wire:model="err_count_threshold" wire:key="err-count-threshold-{{ $err_count_enabled ? 'on' : 'off' }}" @disabled(! $err_count_enabled) class="mt-1 block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest disabled:bg-brand-sand/20" />
                    @error('err_count_threshold') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="text-[11px] text-brand-mist">{{ __('Adjust per traffic volume') }}</div>
            </div>
        </div>
        <div class="flex items-center justify-end gap-3 border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-3 sm:px-8">
            <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
                <x-spinner size="sm" variant="muted" />
                {{ __('Saving…') }}
            </span>
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                {{ __('Save thresholds') }}
            </button>
        </div>
    </section>
</div>
