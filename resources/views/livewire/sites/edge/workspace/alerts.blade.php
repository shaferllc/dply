@php
    $hasRepoAlerts = collect($repoAlerts ?? [])->contains(fn ($m) => is_array($m) && ($m['enabled'] ?? false));
@endphp

<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Thresholds') }}</p>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Checked hourly against the last 60 minutes. Breaches notify via your channels (6h cooldown per kind).') }}</p>

        <div class="mt-4 divide-y divide-brand-ink/8 rounded-lg border border-brand-ink/10">
            <div class="grid grid-cols-1 gap-3 px-4 py-3 sm:grid-cols-[1fr_9rem] sm:items-center">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model.live="lcp_enabled" class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                    <span class="text-sm">
                        <span class="font-semibold text-brand-ink">{{ __('LCP p75') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-mist">{{ __('Good: ≤2500ms') }}</span>
                    </span>
                </label>
                <div>
                    <label class="sr-only" for="lcp">{{ __('Threshold (ms)') }}</label>
                    <div class="relative">
                        <input id="lcp" type="number" min="100" max="60000" step="50" wire:model="lcp_threshold" wire:key="lcp-threshold-{{ $lcp_enabled ? 'on' : 'off' }}" @disabled(! $lcp_enabled) class="block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 pr-10 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest disabled:bg-brand-sand/20 dark:border-brand-mist/20 dark:bg-zinc-900" />
                        <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-[10px] text-brand-mist">ms</span>
                    </div>
                    @error('lcp_threshold') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 px-4 py-3 sm:grid-cols-[1fr_9rem] sm:items-center">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model.live="err_rate_enabled" class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                    <span class="text-sm">
                        <span class="font-semibold text-brand-ink">{{ __('5xx error rate') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-mist">{{ __('Healthy: under 1%') }}</span>
                    </span>
                </label>
                <div>
                    <label class="sr-only" for="errrate">{{ __('Threshold (%)') }}</label>
                    <div class="relative">
                        <input id="errrate" type="number" min="0.1" max="100" step="0.1" wire:model="err_rate_threshold" wire:key="err-rate-threshold-{{ $err_rate_enabled ? 'on' : 'off' }}" @disabled(! $err_rate_enabled) class="block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 pr-8 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest disabled:bg-brand-sand/20 dark:border-brand-mist/20 dark:bg-zinc-900" />
                        <span class="pointer-events-none absolute inset-y-0 right-2 flex items-center text-[10px] text-brand-mist">%</span>
                    </div>
                    @error('err_rate_threshold') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 px-4 py-3 sm:grid-cols-[1fr_9rem] sm:items-center">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model.live="err_count_enabled" class="mt-1 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                    <span class="text-sm">
                        <span class="font-semibold text-brand-ink">{{ __('5xx count') }}</span>
                        <span class="mt-0.5 block text-xs text-brand-mist">{{ __('Absolute last-hour total') }}</span>
                    </span>
                </label>
                <div>
                    <label class="sr-only" for="errcnt">{{ __('Threshold') }}</label>
                    <input id="errcnt" type="number" min="1" max="1000000" step="1" wire:model="err_count_threshold" wire:key="err-count-threshold-{{ $err_count_enabled ? 'on' : 'off' }}" @disabled(! $err_count_enabled) class="block w-full rounded-md border border-brand-ink/15 bg-white px-3 py-1.5 font-mono text-xs text-brand-ink focus:border-brand-forest focus:ring-brand-forest disabled:bg-brand-sand/20 dark:border-brand-mist/20 dark:bg-zinc-900" />
                    @error('err_count_threshold') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>
    </section>

    <div class="flex items-center justify-end gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-5 py-3 sm:px-6">
        <span wire:loading.inline-flex wire:target="save" class="inline-flex items-center gap-1.5 text-[11px] text-brand-moss">
            <x-spinner size="sm" variant="muted" />
            {{ __('Saving…') }}
        </span>
        @can('update', $site)
            <button type="button" wire:click="save" wire:loading.attr="disabled" wire:target="save" class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60">
                {{ __('Save') }}
            </button>
        @endcan
    </div>

    <details class="group" @if ($hasRepoAlerts) open @endif>
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 bg-brand-sand/10 px-5 py-3.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-6 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                {{ __('Advanced') }}
                @if ($hasRepoAlerts)
                    <span class="rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ __('Repo') }}</span>
                @endif
            </span>
            <x-heroicon-m-chevron-down class="h-4 w-4 text-brand-mist transition group-open:rotate-180" />
        </summary>

        <div class="space-y-4 border-t border-brand-ink/10 px-5 py-4 sm:px-6">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-[10px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('From :file', ['file' => $sourcePath]) }}</p>
                <a
                    href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                    class="inline-flex items-center gap-1 text-xs font-medium text-brand-sage hover:underline"
                >
                    <x-heroicon-o-arrow-down-tray class="h-3.5 w-3.5" aria-hidden="true" />
                    {{ __('Generate :file', ['file' => $sourcePath]) }}
                </a>
            </div>

            @if ($hasRepoAlerts)
                <ul class="space-y-1 font-mono text-xs text-brand-ink">
                    @foreach (['lcp_p75_ms' => 'LCP p75', 'error_rate' => '5xx rate', 'five_xx_count' => '5xx count'] as $key => $label)
                        @php $m = is_array($repoAlerts[$key] ?? null) ? $repoAlerts[$key] : null; @endphp
                        @if ($m && ($m['enabled'] ?? false))
                            <li>
                                <span class="text-brand-mist">{{ $label }}:</span>
                                {{ $m['threshold'] }}{{ $key === 'error_rate' ? '%' : ($key === 'lcp_p75_ms' ? 'ms' : '') }}
                            </li>
                        @endif
                    @endforeach
                </ul>
                <p class="text-[11px] text-brand-mist">{{ __('Dashboard overrides merge with the repo on the next check.') }}</p>
            @else
                <p class="text-sm text-brand-moss">{{ __('None declared in :file yet.', ['file' => $sourcePath]) }}</p>
            @endif

            <x-edge-yaml-example :file="$sourcePath" :hint="__('Commit thresholds in the repo, or set them above in the dashboard.')">
alerts:
  lcp_p75_ms:
    enabled: true
    threshold: 2500
  error_rate:
    enabled: true
    threshold: 5
  five_xx_count:
    enabled: true
    threshold: 50
            </x-edge-yaml-example>
        </div>
    </details>
</div>
