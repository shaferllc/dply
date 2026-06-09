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
        @if ($removalPending)
            <div class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                <p class="font-semibold">{{ __('dply.yaml was removed from the repo') }}</p>
                <p class="mt-1">{{ __('Steps and processes from the last manifest are still applied. Revert to dashboard control to clear them, or restore the file and redeploy.') }}</p>
                <button type="button" wire:click="revertToDashboard"
                    wire:confirm="{{ __('Clear all dply.yaml-managed steps and processes and return control to the dashboard?') }}"
                    class="mt-3 rounded-md border border-amber-400 bg-white px-3 py-1.5 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                    {{ __('Revert to dashboard') }}
                </button>
            </div>
        @endif

        @if ($pendingRuntimeChange)
            <div class="rounded-xl border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-950">
                <p class="font-semibold">{{ __('Runtime change declared in dply.yaml') }}</p>
                <p class="mt-1 font-mono text-xs">{{ $pendingRuntimeChange['field'] }}: {{ $pendingRuntimeChange['from'] ?? '(unset)' }} → {{ $pendingRuntimeChange['to'] }}</p>
                <p class="mt-1">{{ __('Not auto-applied — it re-provisions the runtime. Apply when ready.') }}</p>
                <button type="button" wire:click="applyRuntimeChange"
                    wire:confirm="{{ __('Apply the runtime change and re-provision this site?') }}"
                    class="mt-3 rounded-md border border-sky-400 bg-white px-3 py-1.5 text-xs font-semibold text-sky-900 hover:bg-sky-100">
                    {{ __('Apply runtime change') }}
                </button>
            </div>
        @endif

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

        @if (count($managed['build']) || count($managed['release']) || count($managed['processes']))
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-lock-closed class="h-4 w-4 text-brand-sage" aria-hidden="true" />
                    <p class="text-sm font-semibold text-brand-ink">{{ __('Managed by dply.yaml (read-only)') }}</p>
                </div>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Reconciled from the repo every deploy. Edit these in dply.yaml — dashboard edits to them are overwritten.') }}</p>
                @foreach (['build' => __('Build steps'), 'release' => __('Release steps')] as $phase => $label)
                    @if (count($managed[$phase]))
                        <div class="mt-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ $label }}</p>
                            <ul class="mt-1 space-y-1">
                                @foreach ($managed[$phase] as $cmd)
                                    <li class="font-mono text-xs text-brand-ink">$ {{ $cmd }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                @endforeach
                @if (count($managed['processes']))
                    <div class="mt-3">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Processes') }}</p>
                        <ul class="mt-1 space-y-1">
                            @foreach ($managed['processes'] as $proc)
                                <li class="font-mono text-xs text-brand-ink">{{ $proc['name'] }} ×{{ $proc['scale'] }} — {{ $proc['command'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        @endif

        <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 pt-4">
            <p class="text-xs text-brand-moss">{{ __('Scaffold a dply.yaml from this site\'s current settings.') }}</p>
            <button type="button" wire:click="exportManifest"
                class="shrink-0 rounded-md border border-brand-forest/30 bg-white px-3 py-1.5 text-xs font-semibold text-brand-forest hover:bg-brand-forest/5">
                {{ __('Export → dply.yaml') }}
            </button>
        </div>
    </div>
</section>
