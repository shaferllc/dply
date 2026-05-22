{{--
  Container launch progress banner. Rendered on whichever server-workspace
  page the user lands on after kicking off a container site (overview for
  Docker hosts, cluster page for K8s). Polls every 5s until the launch
  reaches a terminal state.

  Required vars:
    - $containerLaunch : array<string, mixed>|null (the summary from containerLaunchSummary())
    - $containerLaunchTranscript : string (formatted event log)
--}}
@if (! empty($containerLaunch))
    <section wire:poll.5s data-testid="container-launch-progress" class="overflow-hidden rounded-[2rem] border {{ $containerLaunch['is_failed'] ? 'border-rose-300 bg-rose-50/90' : 'border-sky-200 bg-sky-50/90' }} p-6 shadow-sm">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 flex-1 space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center gap-2 rounded-full border {{ $containerLaunch['is_failed'] ? 'border-rose-300' : 'border-sky-300' }} bg-white px-3 py-1.5 text-[11px] font-semibold uppercase tracking-[0.18em] {{ $containerLaunch['is_failed'] ? 'text-rose-700' : 'text-sky-700' }}">
                        <span class="h-2 w-2 rounded-full {{ $containerLaunch['is_failed'] ? 'bg-rose-500' : 'bg-sky-500 animate-pulse' }}"></span>
                        {{ $containerLaunch['is_failed'] ? __('Container launch failed') : __('Container launch') }}
                    </span>
                    <span class="inline-flex items-center rounded-full border {{ $containerLaunch['is_failed'] ? 'border-rose-200' : 'border-sky-200' }} bg-white px-3 py-1.5 text-xs font-medium {{ $containerLaunch['is_failed'] ? 'text-rose-700' : 'text-sky-700' }}">
                        {{ str($containerLaunch['target_family'])->headline() }}
                    </span>
                </div>
                <h3 class="text-2xl font-semibold tracking-tight text-brand-ink">{{ $containerLaunch['current_step_label'] }}</h3>
                <p class="text-sm leading-6 text-brand-moss">{{ $containerLaunch['summary'] }}</p>
                @if ($containerLaunch['site_route'])
                    <a href="{{ $containerLaunch['site_route'] }}" wire:navigate class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold uppercase tracking-wide text-white transition-colors hover:bg-sky-700">
                        {{ __('Open container site') }}
                    </a>
                @endif
            </div>
        </div>

        <ol class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($containerLaunch['steps'] as $step)
                @php
                    $stateClasses = match ($step['state']) {
                        'completed' => 'border-emerald-200 bg-white text-emerald-800',
                        'active' => 'border-sky-300 bg-white text-sky-800 ring-2 ring-sky-200',
                        default => 'border-slate-200 bg-white/70 text-slate-500',
                    };
                @endphp
                <li class="rounded-xl border {{ $stateClasses }} p-3">
                    <div class="flex items-center gap-2">
                        @if ($step['state'] === 'completed')
                            <x-heroicon-m-check-circle class="h-4 w-4 text-emerald-600" />
                        @elseif ($step['state'] === 'active')
                            <span class="inline-flex h-4 w-4 items-center justify-center">
                                <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-sky-500"></span>
                            </span>
                        @else
                            <span class="inline-block h-2 w-2 rounded-full bg-slate-300"></span>
                        @endif
                        <span class="text-xs font-semibold uppercase tracking-wide">{{ __('Step :n', ['n' => $loop->iteration]) }}</span>
                    </div>
                    <p class="mt-2 text-sm font-medium text-brand-ink">{{ $step['label'] }}</p>
                </li>
            @endforeach
        </ol>

        @if (($containerLaunchTranscript ?? '') !== '')
            <div class="mt-6">
                <p class="text-[11px] font-semibold uppercase tracking-wide {{ $containerLaunch['is_failed'] ? 'text-rose-700' : 'text-sky-700' }}">{{ __('Recent events') }}</p>
                <pre class="mt-2 max-h-64 overflow-auto whitespace-pre-wrap rounded-lg border {{ $containerLaunch['is_failed'] ? 'border-rose-200' : 'border-sky-200' }} bg-white px-3 py-3 font-mono text-[11px] leading-5 text-brand-ink">{{ $containerLaunchTranscript }}</pre>
            </div>
        @endif
    </section>
@endif
