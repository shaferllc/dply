@php
    $tabs = [
        'activations' => __('Activations'),
        'visits' => __('Visits'),
        'runtime' => __('Runtime output'),
        'deploy' => __('Deploy logs'),
    ];
    $tabCounts = [
        'activations' => $activations->count(),
        'visits' => $visits->count(),
        'runtime' => count($runtimeLines),
        'deploy' => $deployments->count(),
    ];
    $tabIcons = [
        'activations' => 'heroicon-o-bolt',
        'visits' => 'heroicon-o-globe-alt',
        'runtime' => 'heroicon-o-command-line',
        'deploy' => 'heroicon-o-rocket-launch',
    ];
@endphp
<div class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-document-text class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Logs') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $tabs[$tab] }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                @switch($tab)
                    @case('visits')
                        {{ __('Organic HTTP traffic — every request real users made, reported by the function as it served them.') }}
                        @break
                    @case('runtime')
                        {{ __('Application stdout, stderr & Laravel log lines from recent invocations — oldest first.') }}
                        @break
                    @case('deploy')
                        {{ __('Build, push, and release steps from every recorded deploy of this function.') }}
                        @break
                    @default
                        {{ __('Invocations dply made itself — background ticks and test requests. Each carries the function\'s runtime logs.') }}
                @endswitch
            </p>
        </div>
        <button type="button" wire:click="refreshLogs" wire:loading.attr="disabled"
                class="ml-auto shrink-0 inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
            {{ __('Refresh') }}
        </button>
    </div>

    <div class="px-6 py-6 sm:px-7 space-y-5">
    {{-- Tab bar — every log source a DigitalOcean Functions host exposes. --}}
    <x-server-workspace-tablist :aria-label="__('Log sources')" class="!mb-0">
        @foreach ($tabs as $key => $label)
            <x-server-workspace-tab
                id="logs-tab-{{ $key }}"
                :active="$tab === $key"
                :icon="$tabIcons[$key]"
                wire:click="setTab('{{ $key }}')"
            >
                {{ $label }}
                <span class="ml-1 text-[10px] text-brand-moss/60">{{ $tabCounts[$key] }}</span>
            </x-server-workspace-tab>
        @endforeach
    </x-server-workspace-tablist>

    {{-- ── Activations ─────────────────────────────────────────────────── --}}
    @if ($tab === 'activations')
        @if ($activations->isNotEmpty())
            <dl class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach ([
                    ['label' => __('Invocations'), 'value' => $metrics['total']],
                    ['label' => __('Error rate'), 'value' => $metrics['error_rate'].'%'],
                    ['label' => __('Avg duration'), 'value' => $metrics['avg_duration'].'ms'],
                    ['label' => __('Cold starts'), 'value' => $metrics['cold_starts']],
                ] as $card)
                    <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3">
                        <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss/70">{{ $card['label'] }}</dt>
                        <dd class="mt-0.5 text-lg font-bold text-brand-ink">{{ $card['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        {{-- Send test request --}}
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-brand-moss">{{ __('Invoke the function now to generate an activation and see its logs.') }}</p>
                <button type="button" wire:click="toggleTestForm"
                        class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
                    {{ $testFormOpen ? __('Cancel') : __('Send test request') }}
                </button>
            </div>
            @if ($testFormOpen)
                <div class="mt-3 flex flex-wrap items-end gap-2">
                    <label class="text-xs text-brand-moss">
                        <span class="block font-semibold">{{ __('Method') }}</span>
                        <select wire:model="testMethod" class="mt-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-xs">
                            @foreach (['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'] as $m)
                                <option value="{{ $m }}">{{ $m }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="flex-1 text-xs text-brand-moss">
                        <span class="block font-semibold">{{ __('Path') }}</span>
                        <input type="text" wire:model="testPath" placeholder="/"
                               class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 font-mono text-xs">
                    </label>
                    <button type="button" wire:click="sendTestRequest" wire:loading.attr="disabled" wire:target="sendTestRequest"
                            class="inline-flex items-center rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-forest/90 disabled:opacity-60">
                        <span wire:loading.remove wire:target="sendTestRequest">{{ __('Send') }}</span>
                        <span wire:loading wire:target="sendTestRequest">{{ __('Invoking…') }}</span>
                    </button>
                </div>
                <p class="mt-2 text-[11px] text-brand-moss/60">{{ __('A test invocation runs the function once and is billed like any invocation.') }}</p>
            @endif
        </div>

        @if ($activations->isEmpty())
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
                {{ __('No activations recorded yet. Background ticks land here automatically; or send a test request above.') }}
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($activations as $invocation)
                    @include('livewire.serverless.partials._invocation-row', ['invocation' => $invocation])
                @endforeach
            </ul>
        @endif

    {{-- ── Visits ──────────────────────────────────────────────────────── --}}
    @elseif ($tab === 'visits')
        @if ($visits->isEmpty())
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
                {{ __('No visits recorded yet. Organic requests appear here once the deployed function reports them — redeploy if this function predates log shipping.') }}
            </div>
        @else
            <ul class="divide-y divide-brand-ink/10">
                @foreach ($visits as $invocation)
                    @include('livewire.serverless.partials._invocation-row', ['invocation' => $invocation])
                @endforeach
            </ul>
        @endif

    {{-- ── Runtime output ──────────────────────────────────────────────── --}}
    @elseif ($tab === 'runtime')
        @if (count($runtimeLines) === 0)
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
                {{ __('No runtime output yet — no recent invocation has written to stdout, stderr, or the Laravel log.') }}
            </div>
        @else
            <p class="text-xs text-brand-moss/60">{{ __(':n lines from recent invocations, oldest first.', ['n' => count($runtimeLines)]) }}</p>
            <pre class="max-h-[28rem] overflow-auto rounded-lg bg-brand-ink p-4 text-[11px] leading-relaxed text-brand-cream">{{ implode("\n", $runtimeLines) }}</pre>
        @endif

    {{-- ── Deploy logs ─────────────────────────────────────────────────── --}}
    @elseif ($tab === 'deploy')
        @if ($deployments->isEmpty())
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/30 px-4 py-3 text-sm text-brand-moss">
                {{ __('No deploys recorded yet — this function has not been deployed.') }}
            </div>
        @else
            <ul class="space-y-2">
                @foreach ($deployments as $deployment)
                    @php
                        $serverlessSteps = $deployment->phaseSteps('serverless');
                        $rawOutput = trim((string) ($deployment->log_output ?? ''));
                    @endphp
                    <li class="rounded-xl border border-brand-ink/10 bg-white p-3">
                        <details>
                            <summary class="cursor-pointer list-none">
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <span @class([
                                        'inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold',
                                        'bg-brand-forest/15 text-brand-forest' => $deployment->status === \App\Models\SiteDeployment::STATUS_SUCCESS,
                                        'bg-rose-100 text-rose-700' => $deployment->status === \App\Models\SiteDeployment::STATUS_FAILED,
                                        'bg-brand-sand text-brand-moss' => ! in_array($deployment->status, [\App\Models\SiteDeployment::STATUS_SUCCESS, \App\Models\SiteDeployment::STATUS_FAILED], true),
                                    ])>{{ $deployment->status }}</span>
                                    <span class="text-brand-moss">{{ $deployment->started_at?->diffForHumans() ?? '—' }}</span>
                                    @if ($deployment->trigger)
                                        <span class="text-brand-moss/60">· {{ $deployment->trigger }}</span>
                                    @endif
                                    @if ($deployment->git_sha)
                                        <span class="font-mono text-[11px] text-brand-moss/60">· {{ \Illuminate\Support\Str::limit($deployment->git_sha, 8, '') }}</span>
                                    @endif
                                    <a href="{{ route('sites.deployments.show', ['server' => $site->server, 'site' => $site, 'deployment' => $deployment]) }}"
                                       wire:navigate
                                       class="ml-auto rounded bg-brand-sand/60 px-1.5 py-0.5 font-mono text-[10px] text-brand-moss hover:bg-brand-sand"
                                       title="{{ __('Open deployment detail') }}">{{ $deployment->id }}</a>
                                </div>
                            </summary>
                            <div class="mt-3 space-y-3">
                                @if (count($serverlessSteps) > 0)
                                    <ul class="space-y-1">
                                        @foreach ($serverlessSteps as $step)
                                            @php
                                                $stepState = (string) ($step['state'] ?? '');
                                                $stepOk = ($step['ok'] ?? false) === true;
                                                $stepFailed = $stepState === 'failed';
                                                $stepDuration = (int) ($step['duration_ms'] ?? 0);
                                            @endphp
                                            <li class="flex items-start gap-2 text-xs">
                                                <span @class([
                                                    'mt-0.5 inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full text-[9px] font-bold',
                                                    'bg-brand-forest/15 text-brand-forest' => $stepOk,
                                                    'bg-rose-100 text-rose-700' => $stepFailed,
                                                    'bg-brand-sand text-brand-moss' => ! $stepOk && ! $stepFailed,
                                                ])>{{ $stepOk ? '✓' : ($stepFailed ? '✗' : '·') }}</span>
                                                <div class="min-w-0 flex-1">
                                                    <p class="text-[11px] text-brand-ink">{{ $step['label'] ?? __('Step') }}</p>
                                                    @if (! empty($step['detail']))
                                                        <p class="break-all font-mono text-[10px] text-brand-moss/70">{{ $step['detail'] }}</p>
                                                    @endif
                                                </div>
                                                @if ($stepDuration > 0)
                                                    <span class="font-mono text-[10px] text-brand-moss/50">{{ $stepDuration }}ms</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif

                                @if ($rawOutput !== '')
                                    <div>
                                        <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Deploy output') }}</p>
                                        <pre class="mt-1 max-h-64 overflow-auto rounded-lg bg-brand-ink p-3 text-[11px] leading-relaxed text-brand-cream">{{ $rawOutput }}</pre>
                                    </div>
                                @elseif (count($serverlessSteps) === 0)
                                    <p class="text-xs text-brand-moss/60">{{ __('No step detail or output captured for this deploy.') }}</p>
                                @endif
                            </div>
                        </details>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
    </div>
</div>
