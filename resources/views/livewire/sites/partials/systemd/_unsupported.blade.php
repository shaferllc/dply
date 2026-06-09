<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <x-icon-badge>
            <x-heroicon-o-information-circle class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Runtime') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Systemd services not used for this site') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                @if (in_array((string) ($site->runtime ?? ''), ['php', 'static'], true))
                    {{ __('PHP and static sites are served by PHP-FPM or nginx directly. Queue workers and schedulers belong on Workers (Supervisor) and Cron — not systemd units.') }}
                @else
                    {{ __('Container and serverless apps manage processes in the platform runtime, not host systemd.') }}
                @endif
            </p>
            <div class="mt-4 flex flex-wrap gap-3 text-sm font-semibold">
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
