@php
    $pill = function (string $status): array {
        return match ($status) {
            'succeeded' => ['label' => __('Succeeded'), 'class' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/30'],
            'running' => ['label' => __('Running'), 'class' => 'bg-sky-100 text-sky-900 ring-sky-200'],
            'failed' => ['label' => __('Failed'), 'class' => 'bg-red-100 text-red-900 ring-red-200'],
            'skipped' => ['label' => __('Skipped'), 'class' => 'bg-brand-sand/60 text-brand-moss ring-brand-ink/10'],
            default => ['label' => __('Pending'), 'class' => 'bg-brand-sand/40 text-brand-mist ring-brand-ink/10'],
        };
    };
    $stepLabel = function (string $key): string {
        return match ($key) {
            'push_ssh_key' => __('Push ephemeral SSH key'),
            'eligibility_scan' => __('Verify sites are still eligible'),
            'revoke_ssh_key' => __('Revoke ephemeral SSH key'),
            'freeze_snapshot' => __('Freeze source snapshot'),
            'clone_repo' => __('Clone repository to dply server'),
            'copy_env' => __('Copy environment variables'),
            'dump_database' => __('Dump database from Ploi'),
            'restore_database' => __('Restore database on dply'),
            'recreate_crons' => __('Recreate cron jobs'),
            'recreate_daemons' => __('Recreate worker daemons'),
            'recreate_scheduler' => __('Recreate Laravel scheduler'),
            'setup_ssl' => __('Set up SSL certificate'),
            'cutover_maintenance_on' => __('Cutover · Enable Ploi maintenance mode'),
            'cutover_db_delta' => __('Cutover · Final database delta'),
            'cutover_dns_swap' => __('Cutover · Swap DNS to dply'),
            'cutover_webhook_swap' => __('Cutover · Re-point git webhooks'),
            'cutover_smoke_test' => __('Cutover · Smoke test'),
            default => $key,
        };
    };
@endphp

<div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 lg:px-8" @if ($shouldPoll) wire:poll.5s @endif>
    <header class="mb-6 space-y-2">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Migration · :id', ['id' => $migration->id]) }}</h1>
            @php $statusPill = $pill($migration->status); @endphp
            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide ring-1 {{ $statusPill['class'] }}">
                {{ $migration->status }}
            </span>
        </div>
        <p class="text-sm text-brand-moss">
            {{ __('Source: Ploi server #:src · Target dply server: :target', [
                'src' => $migration->source_server_id,
                'target' => optional($migration->targetServer)->name ?? __('(pending)'),
            ]) }}
        </p>
        @if ($migration->ssh_key_pushed_at && ! $migration->ssh_key_revoked_at)
            <p class="text-xs text-brand-moss">
                {{ __('Ephemeral SSH key on Ploi side, pushed :pushed.', ['pushed' => $migration->ssh_key_pushed_at->diffForHumans()]) }}
            </p>
        @elseif ($migration->ssh_key_revoked_at)
            <p class="text-xs text-brand-moss">
                {{ __('Ephemeral SSH key revoked :revoked.', ['revoked' => $migration->ssh_key_revoked_at->diffForHumans()]) }}
            </p>
        @endif
    </header>

    {{-- Server-level steps --}}
    @if ($serverSteps->isNotEmpty())
        <section class="dply-card overflow-hidden mb-6">
            <header class="border-b border-brand-ink/10 bg-brand-cream/40 px-5 py-3">
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Server-level steps') }}</h2>
            </header>
            <ul class="divide-y divide-brand-ink/5">
                @foreach ($serverSteps as $step)
                    @php $sp = $pill($step->status); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0 space-y-0.5">
                            <p class="text-sm font-medium text-brand-ink">{{ $stepLabel($step->step_key) }}</p>
                            @if ($step->error_message)
                                <p class="font-mono text-xs text-red-900">{{ $step->error_message }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $sp['class'] }}">
                                {{ $sp['label'] }}
                            </span>
                            @if ($step->status === 'failed')
                                <button type="button" wire:click="retryFailedStep('{{ $step->id }}')" class="text-xs font-semibold text-brand-forest underline underline-offset-2 hover:text-brand-ink">
                                    {{ __('Retry') }}
                                </button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Per-site steps --}}
    @foreach ($migration->siteMigrations as $site)
        @php
            $sitePill = $pill($site->status);
            $cutoverReady = $site->status === 'ready_for_cutover';
        @endphp
        <article class="dply-card overflow-hidden mb-6">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-5 py-3">
                <div class="space-y-0.5">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ $site->domain }}</h2>
                    <p class="text-xs text-brand-moss">{{ $site->site_type }}@if ($site->ssl_strategy) · SSL: {{ $site->ssl_strategy }} @endif</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $sitePill['class'] }}">
                        {{ $sitePill['label'] }}
                    </span>
                    @if ($cutoverReady)
                        <button type="button" wire:click="beginCutover('{{ $site->id }}')" class="inline-flex items-center justify-center rounded-xl bg-amber-500 px-3 py-1.5 text-xs font-semibold text-amber-950 hover:bg-amber-400">
                            {{ __('Begin cutover') }}
                        </button>
                    @endif
                </div>
            </header>
            @if ($cutoverReady)
                <div class="border-b border-amber-200 bg-amber-50/70 px-5 py-3 text-xs text-amber-950">
                    <p class="font-semibold">{{ __('Ready to cut over.') }}</p>
                    <p class="mt-1 leading-relaxed">{{ __('Clicking Begin cutover puts the Ploi site in maintenance mode, captures the final DB delta, swaps DNS, and re-points webhooks. Allow ~5 minutes; the smoke test polls until propagation completes.') }}</p>
                </div>
            @endif
            <ul class="divide-y divide-brand-ink/5">
                @foreach ($site->steps as $step)
                    @php $sp = $pill($step->status); @endphp
                    <li class="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                        <div class="min-w-0 space-y-0.5">
                            <p class="text-sm font-medium text-brand-ink">{{ $stepLabel($step->step_key) }}</p>
                            @if ($step->error_message)
                                <p class="font-mono text-xs text-red-900">{{ $step->error_message }}</p>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $sp['class'] }}">
                                {{ $sp['label'] }}
                            </span>
                            @if ($step->status === 'failed')
                                <button type="button" wire:click="retryFailedStep('{{ $step->id }}')" class="text-xs font-semibold text-brand-forest underline underline-offset-2 hover:text-brand-ink">
                                    {{ __('Retry') }}
                                </button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </article>
    @endforeach

    @php $reviewItems = $migration->manual_review_items ?? []; @endphp
    @if (! empty($reviewItems))
        <section class="dply-card mt-8 overflow-hidden">
            <header class="border-b border-brand-ink/10 bg-amber-50/70 px-5 py-3">
                <h2 class="text-sm font-semibold text-amber-950">{{ __('Manual review — items dply did not migrate') }}</h2>
                <p class="text-xs text-amber-900">{{ __('These existed on your Ploi server but require manual handling on dply. Review each one and dismiss when done.') }}</p>
            </header>
            <ul class="divide-y divide-brand-ink/5">
                @foreach ($reviewItems as $idx => $item)
                    @php
                        $dismissed = ! empty($item['dismissed_at'] ?? null);
                        $rawJson = ! empty($item['raw'] ?? []) ? json_encode($item['raw'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : null;
                    @endphp
                    <li class="px-5 py-3 {{ $dismissed ? 'opacity-50' : '' }}">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 space-y-1">
                                <p class="text-sm font-medium text-brand-ink">{{ $item['title'] ?? $item['kind'] }}</p>
                                <p class="text-xs text-brand-moss leading-relaxed">{{ $item['detail'] ?? '' }}</p>
                                @if ($rawJson)
                                    <pre class="mt-2 max-h-48 overflow-auto rounded-md bg-brand-cream px-3 py-2 text-[11px] font-mono text-brand-ink">{{ $rawJson }}</pre>
                                @endif
                            </div>
                            @if (! $dismissed)
                                <button type="button" wire:click="dismissReviewItem({{ $idx }})" class="text-xs font-semibold text-brand-forest underline underline-offset-2 hover:text-brand-ink">
                                    {{ __('Mark reviewed') }}
                                </button>
                            @else
                                <span class="text-[10px] uppercase tracking-wide text-brand-moss">{{ __('Reviewed') }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    <footer class="mt-8">
        <a href="{{ route('imports.ploi.inventory') }}" wire:navigate class="text-sm font-medium text-brand-forest underline underline-offset-2 hover:text-brand-ink">
            ← {{ __('Back to Ploi inventory') }}
        </a>
    </footer>
</div>
