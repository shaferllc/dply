@php
    $btnPrimary = 'inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-cream shadow-sm hover:bg-brand-forest transition-colors disabled:cursor-not-allowed disabled:opacity-50';
    $btnSecondary = 'inline-flex items-center justify-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-xs font-semibold uppercase tracking-wide text-brand-ink shadow-sm hover:bg-brand-sand/50 transition-colors';
@endphp

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <nav class="text-sm text-brand-moss mb-6" aria-label="{{ __('Breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.index') }}" wire:navigate class="hover:text-brand-ink transition-colors">{{ __('Servers') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('servers.sites', $server) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[10rem]" title="{{ $server->name }}">{{ $server->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('sites.show', [$server, $site]) }}" wire:navigate class="hover:text-brand-ink transition-colors truncate max-w-[10rem]" title="{{ $site->name }}">{{ $site->name }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('Insights') }}</li>
        </ol>
    </nav>

    <header class="mb-8 pb-6 border-b border-brand-ink/10 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-brand-ink">{{ __('Insights') }}</h1>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Monitoring and recommendations for this site.') }}</p>
        </div>
        <button type="button" wire:click="runChecksNow" wire:loading.attr="disabled" class="{{ $btnPrimary }}">
            <span wire:loading.remove wire:target="runChecksNow">{{ __('Refresh') }}</span>
            <span wire:loading wire:target="runChecksNow">{{ __('Queueing…') }}</span>
        </button>
    </header>

    @if (session('success'))
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">{{ session('success') }}</div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
        <x-server-workspace-tablist ariaLabel="{{ __('Insights sections') }}">
            <x-server-workspace-tab wire:click="setTab('overview')" :active="$tab === 'overview'">{{ __('Overview') }}</x-server-workspace-tab>
            <x-server-workspace-tab wire:click="setTab('notifications')" :active="$tab === 'notifications'">{{ __('Notifications') }}</x-server-workspace-tab>
            <x-server-workspace-tab wire:click="setTab('settings')" :active="$tab === 'settings'">{{ __('Settings') }}</x-server-workspace-tab>
        </x-server-workspace-tablist>
    </div>

    @if ($tab === 'overview')
        <div class="rounded-2xl border border-brand-ink/10 bg-white shadow-sm overflow-hidden">
            <div class="border-b border-brand-ink/10 px-5 py-4">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Findings for this site') }}</h2>
            </div>
            @if ($findings->isEmpty())
                <p class="px-5 py-10 text-sm text-brand-moss text-center">{{ __('No findings yet.') }}</p>
            @else
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($findings as $f)
                        @php
                            $fix = config('insights.insights.'.$f->insight_key.'.fix');
                            $canFix = is_array($fix) && ($fix['action'] ?? null);
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
                            </div>
                            @if ($canFix)
                                <button type="button" wire:click="applyFix({{ $f->id }})" wire:confirm="{{ __('Apply the suggested fix on the server?') }}" class="{{ $btnSecondary }} shrink-0">
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
            <p>{{ __('Insights alerts use the server’s “Insights alerts” subscription. Configure channels from your profile, then assign the server event server.insights_alerts.') }}</p>
            <p>
                <a href="{{ route('profile.notification-channels') }}" wire:navigate class="font-medium text-brand-forest underline">{{ __('Manage notification channels') }}</a>
            </p>
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
</div>
