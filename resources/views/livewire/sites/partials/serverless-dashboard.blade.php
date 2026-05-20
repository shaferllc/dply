@php
    $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];
    $invocationUrl = trim((string) ($serverless['action_url'] ?? ''));
    $runtime = trim((string) ($serverless['runtime'] ?? ''));
    $lastDeployedAt = $serverless['last_deployed_at'] ?? null;
    $revision = trim((string) ($serverless['last_revision_id'] ?? ''));
    $isActive = $site->status === \App\Models\Site::STATUS_FUNCTIONS_ACTIVE;
    $statusBadgeClass = $isActive ? 'bg-emerald-100 text-emerald-800' : 'bg-sky-100 text-sky-800';
    $statusLabel = $isActive ? __('Live') : __('Configured — deploying');
@endphp

<section class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-sky-700">{{ __('Serverless') }}</p>
            <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ __('Function') }}</h2>
            <p class="mt-1 text-sm text-slate-600">{{ __('HTTP-triggered function on DigitalOcean Functions.') }}</p>
        </div>
        <span class="inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold {{ $statusBadgeClass }}">{{ $statusLabel }}</span>
    </div>

    @if ($invocationUrl !== '')
        <div x-data="{ copied: false }">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Invocation URL') }}</p>
            <div class="mt-1 flex items-center gap-2">
                <code class="flex-1 min-w-0 truncate rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-800">{{ $invocationUrl }}</code>
                <button type="button"
                        x-on:click="navigator.clipboard.writeText(@js($invocationUrl)); copied = true; setTimeout(() => copied = false, 1500)"
                        class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-slate-400">
                    <span x-show="!copied">{{ __('Copy') }}</span>
                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                </button>
                <a href="{{ $invocationUrl }}" target="_blank" rel="noopener noreferrer"
                   class="inline-flex items-center rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:border-slate-400">
                    {{ __('Open') }}
                </a>
            </div>
        </div>
    @else
        <div class="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3 text-sm text-slate-600">
            {{ __('The invocation URL appears here once the first deploy completes.') }}
        </div>
    @endif

    <dl class="grid grid-cols-2 gap-4 sm:grid-cols-4 text-sm">
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Runtime') }}</dt>
            <dd class="mt-0.5 text-slate-800">{{ $runtime !== '' ? $runtime : '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Repository') }}</dt>
            <dd class="mt-0.5 truncate text-slate-800">{{ $site->git_repository_url ?: '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Last deployed') }}</dt>
            <dd class="mt-0.5 text-slate-800">
                {{ $lastDeployedAt ? \Illuminate\Support\Carbon::parse($lastDeployedAt)->diffForHumans() : __('Never') }}
            </dd>
        </div>
        <div>
            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ __('Revision') }}</dt>
            <dd class="mt-0.5 text-slate-800">{{ $revision !== '' ? $revision : '—' }}</dd>
        </div>
    </dl>

    <div class="pt-1">
        <a href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploy']) }}"
           class="inline-flex items-center rounded-xl bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
            {{ __('Deploy / redeploy') }}
        </a>
    </div>
</section>
