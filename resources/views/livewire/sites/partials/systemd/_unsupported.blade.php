@php
    $isPhpOrStatic = in_array((string) ($site->runtime ?? ''), ['php', 'static'], true);
    // A php/static site has no systemd web tier, but it can still be running
    // worker units (how the VM path ran Horizon). Name them rather than say
    // systemd is unused. After a move to Supervisor the rows stay — they are
    // what gets mirrored — but the units are gone, so this goes quiet.
    $workerUnits = $isPhpOrStatic
        && app(\App\Services\WorkerPools\WorkerDaemonBackend::class)->backendFor($site) === \App\Models\WorkerPool::PM_SYSTEMD
        ? $site->processes->where('is_active', true)->where('type', '!=', \App\Models\SiteProcess::TYPE_WEB)->values()
        : collect();
@endphp
<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-information-circle class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Runtime') }}</p>
            @if ($workerUnits->isNotEmpty())
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ trans_choice(':count worker still runs as a systemd unit|:count workers still run as systemd units', $workerUnits->count(), ['count' => $workerUnits->count()]) }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('PHP sites run workers under Supervisor, where deploy restarts and the Queue page manage them. These units predate that.') }}</p>
                <ul class="mt-3 divide-y divide-brand-ink/10 rounded-lg border border-brand-ink/10 bg-white">
                    @foreach ($workerUnits as $unit)
                        <li class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                            <span class="font-semibold text-brand-ink">{{ $unit->name }}</span>
                            <code class="ml-auto truncate font-mono text-xs text-brand-moss" title="{{ $unit->command }}">{{ $unit->command }}</code>
                        </li>
                    @endforeach
                </ul>
            @else
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Systemd services not used for this site') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    @if ($isPhpOrStatic)
                        {{ __('PHP and static sites are served by PHP-FPM or nginx directly. Queue workers and schedulers belong on Workers (Supervisor) and Cron — not systemd units.') }}
                    @else
                        {{ __('Container and serverless apps manage processes in the platform runtime, not host systemd.') }}
                    @endif
                </p>
            @endif
            <div class="mt-4 flex flex-wrap gap-3 text-sm font-semibold">
                @if ($workerUnits->isNotEmpty())
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'queue']) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">
                        {{ __('Move them to Supervisor') }} →
                    </a>
                @endif
                <a href="{{ route('sites.daemons', ['server' => $server, 'site' => $site]) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">
                    {{ __('Open Workers') }} →
                </a>
                <a href="{{ route('servers.cron', ['server' => $server, 'site' => $site]) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">
                    {{ __('Open Cron jobs') }} →
                </a>
                @if ($site->isLaravelFrameworkDetected())
                    <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'laravel-stack']) }}" wire:navigate class="text-brand-forest hover:text-brand-sage hover:underline">
                        {{ __('Open Laravel') }} →
                    </a>
                @endif
            </div>
        </div>
    </div>
</section>
