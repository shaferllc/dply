@php
    $snapshot = $snapshot ?? null;
@endphp

<section class="dply-card overflow-hidden">
    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
        <div class="flex min-w-0 items-start gap-3">
            <x-icon-badge>
                <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Config') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('dply.yaml (in-repo)') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('Commit redirects, site crons, server crons, and deploy hooks in dply.yaml — Dply syncs them after each deploy on this server.') }}
                </p>
            </div>
        </div>
        @if ($snapshot)
            <span class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-full bg-emerald-50 px-2.5 py-1 text-[11px] font-semibold text-emerald-800 ring-1 ring-emerald-200">
                <x-heroicon-o-check-circle class="h-4 w-4" />
                {{ __('Synced') }}
            </span>
        @endif
    </div>

    <div class="space-y-4 px-6 py-6 sm:px-8">
        @if ($snapshot === null)
            <p class="text-sm text-brand-moss">{{ __('No dply.yaml found on the last deploy. Add one at the repo root and redeploy to sync redirects, crons, and hooks.') }}</p>
            <pre class="overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 font-mono text-[11px] leading-relaxed text-brand-ink"># dply.yaml — BYO example
redirects:
  - from: /docs/*
    to: https://docs.example.com/:splat
    status: 301

crons:
  - schedule: "0 * * * *"
    command: "cd /home/dply/your-site && php artisan schedule:run"

server_crons:
  - schedule: "15 2 * * *"
    command: "/usr/local/bin/dply-backup-runner"
    user: root

deploy_hooks:
  - phase: after_clone
    script: |
      composer install --no-dev -o</pre>
        @else
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Source file') }}</dt>
                    <dd class="mt-1 font-mono text-brand-ink">{{ $snapshot['source_path'] ?? 'dply.yaml' }}</dd>
                </div>
                @if ($snapshot['synced_at'])
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Last synced') }}</dt>
                        <dd class="mt-1 text-brand-ink">{{ $snapshot['synced_at'] }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Managed rules') }}</dt>
                    <dd class="mt-1 text-brand-moss">
                        {{ trans(':redirects redirects · :rewrites rewrites · :crons site crons · :server_crons server crons · :hooks hooks · :env env vars', [
                            'redirects' => $snapshot['counts']['redirects'] ?? 0,
                            'rewrites' => $snapshot['counts']['rewrites'] ?? 0,
                            'crons' => $snapshot['counts']['crons'] ?? 0,
                            'server_crons' => $snapshot['counts']['server_crons'] ?? 0,
                            'hooks' => $snapshot['counts']['deploy_hooks'] ?? 0,
                            'env' => $snapshot['counts']['env_declarations'] ?? 0,
                        ]) }}
                    </dd>
                </div>
            </dl>

            @if (! empty($snapshot['warnings']))
                <div class="rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                    <p class="font-semibold">{{ __('Parse warnings') }}</p>
                    <ul class="mt-2 list-disc space-y-1 ps-5">
                        @foreach ($snapshot['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="text-xs text-brand-moss">{{ __('Redirect changes apply on the next webserver config reload. Site cron rows are scoped to this site; server cron rows apply server-wide — sync crontab from the server workspace to install them on the host.') }}</p>
        @endif
    </div>
</section>
