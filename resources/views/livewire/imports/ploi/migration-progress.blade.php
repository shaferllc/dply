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
        @php $sitePill = $pill($site->status); @endphp
        <article class="dply-card overflow-hidden mb-6">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-cream/40 px-5 py-3">
                <div class="space-y-0.5">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ $site->domain }}</h2>
                    <p class="text-xs text-brand-moss">{{ $site->site_type }}@if ($site->ssl_strategy) · SSL: {{ $site->ssl_strategy }} @endif</p>
                </div>
                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide ring-1 {{ $sitePill['class'] }}">
                    {{ $sitePill['label'] }}
                </span>
            </header>
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

    <footer class="mt-8">
        <a href="{{ route('imports.ploi.inventory') }}" wire:navigate class="text-sm font-medium text-brand-forest underline underline-offset-2 hover:text-brand-ink">
            ← {{ __('Back to Ploi inventory') }}
        </a>
    </footer>
</div>
