@php
    use App\Models\EdgeDeployment;

    // Same pattern as the Routing tab — pull the latest deployment
    // whose repo_config carries a non-empty `crons` block. The SSR
    // and middleware uploaders push these to CF cron triggers on
    // every publish; this panel is the read-only mirror.
    $deploymentsWithConfig = $site->relationLoaded('edgeDeployments') && $site->edgeDeployments !== null
        ? $site->edgeDeployments->filter(fn (EdgeDeployment $d): bool => is_array($d->repo_config) && $d->repo_config !== [])
        : EdgeDeployment::query()
            ->where('site_id', $site->id)
            ->whereNotNull('repo_config')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->filter(fn (EdgeDeployment $d): bool => is_array($d->repo_config) && $d->repo_config !== []);

    $latestConfig = $deploymentsWithConfig
        ->first(fn (EdgeDeployment $d): bool => $d->status === EdgeDeployment::STATUS_LIVE)
        ?->repo_config
        ?? $deploymentsWithConfig->first()?->repo_config;

    $crons = is_array($latestConfig['crons'] ?? null) ? $latestConfig['crons'] : [];
    $sourcePath = is_string($latestConfig['source_path'] ?? null) ? $latestConfig['source_path'] : 'dply.yaml';
    $runtimeMode = (string) ($site->edgeMeta()['runtime_mode'] ?? 'static');
    $cronTarget = $runtimeMode === 'ssr' ? __('SSR worker') : ($runtimeMode === 'hybrid' ? __('middleware worker') : __('the platform worker'));
@endphp

<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-8">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <h3 class="inline-flex items-center gap-2 text-base font-semibold text-brand-ink">
                    <x-heroicon-o-clock class="h-4 w-4 text-brand-forest dark:text-brand-sage" aria-hidden="true" />
                    {{ __('Cron triggers') }}
                </h3>
                <p class="mt-0.5 text-sm text-brand-moss">
                    {{ __('Scheduled invocations of :target. Declared in :file and pushed to Cloudflare on every deploy — edit there, then redeploy.', ['target' => $cronTarget, 'file' => $sourcePath]) }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span class="inline-flex items-center gap-1 rounded-full bg-brand-sand/60 px-2 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-moss">
                    {{ __('Repo-managed') }}
                </span>
                <a
                    href="{{ route('sites.edge.dply-yaml', ['server' => $site->server_id, 'site' => $site->id]) }}"
                    class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 font-mono text-[10px] font-semibold uppercase tracking-wide text-brand-ink hover:bg-brand-sand/40"
                    title="{{ __('Download a dply.yaml that mirrors the current routing + crons') }}"
                >
                    <x-heroicon-o-arrow-down-tray class="h-3 w-3" aria-hidden="true" />
                    {{ __('Generate dply.yaml') }}
                </a>
            </div>
        </div>
    </div>

    @if ($latestConfig === null)
        <div class="px-6 py-8 text-sm text-brand-moss sm:px-8">
            <p>{{ __('No deploy has shipped a :file with crons yet. Add a `crons:` block at the repo root and redeploy.', ['file' => 'dply.yaml']) }}</p>
            <pre class="mt-3 overflow-x-auto rounded-lg bg-brand-ink/95 px-4 py-3 font-mono text-[11px] leading-relaxed text-brand-sand"><code>crons:
  - schedule: "*/5 * * * *"
    handler: "scheduled"
  - schedule: "0 3 * * *"
    handler: "daily"</code></pre>
        </div>
    @elseif ($crons === [])
        <div class="px-6 py-8 text-sm text-brand-moss sm:px-8">
            {{ __('Latest deploy ships no cron triggers.') }}
        </div>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/8 text-xs">
                <thead class="bg-brand-sand/30 text-left text-[10px] font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-4 py-2 sm:px-6">{{ __('Schedule') }}</th>
                        <th class="px-4 py-2">{{ __('Handler') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/8 text-brand-ink">
                    @foreach ($crons as $rule)
                        <tr>
                            <td class="px-4 py-2 font-mono sm:px-6">{{ $rule['schedule'] ?? '—' }}</td>
                            <td class="px-4 py-2 font-mono text-brand-moss">{{ $rule['handler'] ?? __('(default scheduled handler)') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="border-t border-brand-ink/10 bg-brand-sand/20 px-6 py-3 sm:px-8">
            <p class="text-[11px] text-brand-mist">{{ __('Cron times are UTC. Cloudflare runs schedules at-most-once across all colos.') }}</p>
        </div>
    @endif
</section>
