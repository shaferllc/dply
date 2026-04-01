@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
@endphp

<x-server-workspace-layout
    :server="$server"
    active="insights"
    :title="__('Insights')"
    :description="__('Monitoring, recommendations, and optional fixes for this server.')"
>
    @include('livewire.servers.partials.workspace-flashes')

    @if ($server->workspace)
        <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/20 px-5 py-4 text-sm text-brand-ink">
            <p class="font-semibold">{{ __('Project insight context') }}</p>
            <p class="mt-1 leading-relaxed text-brand-moss">
                {{ __('These findings are scoped to this server. For shared incident context, runbooks, and grouped notifications, use the linked project pages for the broader project view.') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-3">
                <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project operations') }}</a>
                <a href="{{ route('projects.access', $server->workspace) }}" wire:navigate class="text-sm font-medium text-brand-ink hover:text-brand-sage">{{ __('Open project access') }}</a>
            </div>
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4">
        <x-server-workspace-tablist ariaLabel="{{ __('Insights sections') }}">
            <x-server-workspace-tab wire:click="setTab('overview')" :active="$tab === 'overview'">{{ __('Overview') }}</x-server-workspace-tab>
            <x-server-workspace-tab wire:click="setTab('notifications')" :active="$tab === 'notifications'">{{ __('Notifications') }}</x-server-workspace-tab>
            <x-server-workspace-tab wire:click="setTab('settings')" :active="$tab === 'settings'">{{ __('Settings') }}</x-server-workspace-tab>
        </x-server-workspace-tablist>
        <button type="button" wire:click="runChecksNow" wire:loading.attr="disabled" class="{{ $btnPrimary }}">
            <span wire:loading.remove wire:target="runChecksNow">{{ __('Refresh') }}</span>
            <span wire:loading wire:target="runChecksNow">{{ __('Queueing…') }}</span>
        </button>
    </div>

    @if ($tab === 'overview')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-brand-ink/10 px-5 py-4">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Open findings') }}</h2>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Server-scoped open findings appear here. Site-specific items are on each site’s Insights page.') }}</p>
            </div>
            @if ($findings->isEmpty())
                <div class="px-5 py-10 text-center">
                    <p class="text-sm font-medium text-brand-ink">{{ __('No open findings right now.') }}</p>
                    <p class="mt-2 text-sm text-brand-moss">{{ __('Run a refresh, wait for the scheduled job, or review settings if you expected a signal here.') }}</p>
                    <div class="mt-4 inline-flex flex-wrap items-center justify-center gap-2 text-xs text-brand-mist">
                        <span class="rounded-full border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5">
                            {{ trans_choice('{1} :count enabled check|[2,*] :count enabled checks', $enabledChecks, ['count' => $enabledChecks]) }}
                        </span>
                        <span class="rounded-full border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5">
                            {{ trans_choice('{1} :count implemented runner enabled|[2,*] :count implemented runners enabled', $implementedChecks, ['count' => $implementedChecks]) }}
                        </span>
                    </div>
                    <p class="mt-4 max-w-2xl mx-auto text-xs leading-6 text-brand-mist">
                        {{ __('Common reasons for an empty list: the server has not finished a queued run yet, matching checks are disabled, some checks only emit findings when thresholds are crossed, or the server does not have recent metrics / matching conditions for those checks.') }}
                    </p>
                </div>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($findings as $f)
                        @php
                            $fix = config('insights.insights.'.$f->insight_key.'.fix');
                            $canFix = is_array($fix) && ($fix['action'] ?? null);
                            $checkedAtRaw = is_array($f->meta ?? null) ? ($f->meta['checked_at'] ?? null) : null;
                            $checkedAt = is_string($checkedAtRaw) && $checkedAtRaw !== ''
                                ? \Carbon\Carbon::parse($checkedAtRaw)
                                : null;
                            $appTimezone = config('app.timezone') ?: 'UTC';
                            $checkedAtLocal = $checkedAt?->copy()->timezone($appTimezone);
                            $checkedAtUtc = $checkedAt?->copy()->timezone('UTC');
                        @endphp
                        <li class="px-5 py-4 flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-xs font-semibold uppercase tracking-wide rounded-md px-2 py-0.5
                                        @class([
                                            'bg-amber-50 text-amber-950' => $f->severity === 'warning',
                                            'bg-red-50 text-red-900' => $f->severity === 'critical',
                                            'bg-brand-sand/80 text-brand-ink' => $f->severity === 'info',
                                        ])">{{ $f->severity }}</span>
                                    <span class="font-medium text-brand-ink">{{ $f->title }}</span>
                                </div>
                                @if ($f->body)
                                    <p class="mt-2 text-sm text-brand-moss whitespace-pre-wrap">{{ $f->body }}</p>
                                @endif
                                @include('livewire.partials.insight-correlation', ['finding' => $f])
                                @if ($f->insight_key === 'insights_pipeline_heartbeat' && $checkedAtLocal && $checkedAtUtc)
                                    <div class="mt-2 space-y-1 text-xs text-brand-mist">
                                        <p>{{ __('App time') }}: {{ $checkedAtLocal->format('Y-m-d H:i:s T') }}</p>
                                        <p>{{ __('UTC') }}: {{ $checkedAtUtc->format('Y-m-d H:i:s T') }}</p>
                                        <p>{{ __('Recorded') }}: {{ $f->detected_at?->timezone($appTimezone)->format('Y-m-d H:i:s T') }}</p>
                                    </div>
                                @else
                                    <p class="mt-2 text-xs text-brand-mist">
                                        {{ __('Detected') }}:
                                        {{ $f->detected_at?->timezone($appTimezone)->format('Y-m-d H:i:s T') ?? '—' }}
                                    </p>
                                @endif
                            </div>
                            @if ($canFix)
                                <button type="button" wire:click="openApplyFixModal({{ $f->id }})" class="{{ $btnSecondary }} shrink-0">
                                    {{ __('Apply fix') }}
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif

    @if ($tab === 'notifications')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm p-6 space-y-3 text-sm text-brand-moss max-w-2xl">
            <p>{{ __('Subscribe to “Insights alerts” on this server from your notification channels. When new findings open (or a resolved issue recurs), subscribed channels receive a short message with a link back here.') }}</p>
            <p>
                <a href="{{ route('profile.notification-channels') }}" wire:navigate class="font-medium text-brand-forest underline">{{ __('Manage notification channels') }}</a>
                ·
                <a href="{{ route('profile.notification-channels.bulk-assign') }}" wire:navigate class="font-medium text-brand-forest underline">{{ __('Bulk-assign event types') }}</a>
            </p>
            <p class="text-xs text-brand-mist">{{ __('Event key: server.insights_alerts') }}</p>
        </div>
    @endif

    @if ($tab === 'settings')
        @include('livewire.partials.insights-settings-form', ['catalog' => $insightsCatalog, 'orgHasPro' => $orgHasPro])
        <div class="flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-brand-ink/10">
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="enableAll" class="{{ $btnSecondary }}">{{ __('Enable all') }}</button>
                <button type="button" wire:click="disableAll" class="{{ $btnSecondary }}">{{ __('Disable all') }}</button>
            </div>
            <button type="button" wire:click="saveSettings" class="{{ $btnPrimary }}">{{ __('Save settings') }}</button>
        </div>
    @endif

    <x-slot name="modals">
        @if ($showApplyFixModal && $selectedFixFinding)
            <div
                class="fixed inset-0 z-50 overflow-y-auto"
                role="dialog"
                aria-modal="true"
                aria-labelledby="apply-insight-fix-title"
                x-data
                x-on:keydown.escape.window="$wire.closeApplyFixModal()"
            >
                <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeApplyFixModal"></div>
                <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                    <div class="relative w-full max-w-md rounded-2xl border border-brand-ink/10 bg-white shadow-xl" wire:click.stop>
                        <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-7">
                            <h2 id="apply-insight-fix-title" class="text-lg font-semibold text-brand-ink">{{ __('Apply suggested fix') }}</h2>
                        </div>
                        <div class="space-y-3 px-6 py-5 sm:px-7">
                            <p class="text-sm leading-relaxed text-brand-moss">
                                {{ __('This will queue the suggested server-side fix for the selected insight.') }}
                            </p>
                            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-4">
                                <p class="text-sm font-semibold text-brand-ink">{{ $selectedFixFinding->title }}</p>
                                @if ($selectedFixFinding->body)
                                    <p class="mt-2 text-sm leading-6 text-brand-moss">{{ $selectedFixFinding->body }}</p>
                                @endif
                            </div>
                        </div>
                        <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3 sm:px-7">
                            <button
                                type="button"
                                wire:click="closeApplyFixModal"
                                class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50"
                            >
                                {{ __('Cancel') }}
                            </button>
                            <button
                                type="button"
                                wire:click="confirmApplyFix"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center justify-center rounded-lg bg-brand-ink px-4 py-2.5 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-50"
                            >
                                <span wire:loading.remove wire:target="confirmApplyFix">{{ __('Queue fix') }}</span>
                                <span wire:loading wire:target="confirmApplyFix">{{ __('Queueing…') }}</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-slot>
</x-server-workspace-layout>
