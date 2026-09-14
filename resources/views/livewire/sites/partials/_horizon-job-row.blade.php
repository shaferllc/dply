{{-- One Horizon job — a Running or History row that opens in place. Everything
     shown opened comes from the envelope the Horizon read already carries; the
     payload (the customer's serialized arguments) is fetched only on request. --}}
@php
    $status = strtolower((string) ($job['status'] ?? ''));
    $jobId = (string) ($job['id'] ?? '');
    $isRunning = in_array($status, ['reserved', 'running'], true);
    $isFailed = $status === 'failed';
    $duration = function (mixed $seconds): string {
        if ($seconds === null || $seconds === '') {
            return '—';
        }
        $s = (int) round((float) $seconds);

        return $s < 90 ? $s.'s' : ($s < 5400 ? round($s / 60).'m' : round($s / 3600, 1).'h');
    };
    $timeout = is_numeric($job['timeout'] ?? null) ? (int) $job['timeout'] : null;
    $horizonUrl = $jobId !== '' && $site->visitUrl()
        ? rtrim((string) $site->visitUrl(), '/').$site->horizonDashboardPath().($isFailed ? '/failed/'.$jobId : '/jobs/'.($status === 'completed' ? 'completed' : 'pending').'/'.$jobId)
        : null;
@endphp
<li wire:key="{{ $rowKey }}" x-data="{ open: false }">
    <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" class="flex w-full flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-left transition hover:bg-brand-sand/25 dply-focus sm:px-5">
        <p class="min-w-0 truncate font-mono text-xs font-semibold {{ $isFailed ? 'text-rose-700' : 'text-brand-ink' }}">{{ $job['name'] ?? 'job' }}</p>
        <p class="shrink-0 text-2xs text-brand-mist">
            @unless ($isRunning)
                <span @class(['rounded-full px-1.5 py-0.5 font-semibold', 'bg-rose-100 text-rose-900' => $isFailed, 'bg-emerald-50 text-emerald-800' => ! $isFailed])>{{ $isFailed ? __('failed') : __('completed') }}</span> ·
            @endunless
            {{ $job['queue'] ?? '?' }}
            @if (($job['age'] ?? null) !== null)
                · {{ $isRunning
                    ? __('running for :s s', ['s' => (int) round((float) $job['age'])])
                    : __(':s s ago', ['s' => (int) round((float) $job['age'])]) }}
            @endif
            <x-heroicon-m-chevron-down class="ml-1 inline-block h-3 w-3 align-middle transition" x-bind:class="open ? 'rotate-180' : ''" aria-hidden="true" />
        </p>
    </button>

    <div x-show="open" x-cloak class="border-t border-brand-ink/10 bg-brand-sand/20 px-4 py-3 sm:px-5">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-2xs sm:grid-cols-4">
            @foreach ([
                __('Job id') => $jobId !== '' ? $jobId : '—',
                __('Attempt') => ($job['attempts'] ?? null) !== null
                    ? __(':n of :m', ['n' => $job['attempts'], 'm' => $job['max_tries'] ?? '∞'])
                    : '—',
                __('Timeout') => $timeout === null
                    ? '—'
                    : ($isRunning && ($job['age'] ?? null) !== null
                        ? __(':t · times out in ~:r', ['t' => $duration($timeout), 'r' => $duration(max(0, $timeout - (float) $job['age']))])
                        : $duration($timeout)),
                __('Waited for a worker') => $duration($job['waited'] ?? null),
                ($isRunning ? __('Running for') : __('Ran for')) => $duration($isRunning ? ($job['age'] ?? null) : ($job['ran'] ?? null)),
            ] as $label => $value)
                <div>
                    <dt class="uppercase tracking-wide text-brand-mist">{{ $label }}</dt>
                    <dd class="mt-0.5 break-all font-mono text-brand-ink">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if (! empty($job['tags']))
            {{-- Horizon tags a job with the models it was given: for a build,
                 which site it is building. --}}
            <div class="mt-2 flex flex-wrap gap-1">
                @foreach ((array) $job['tags'] as $tag)
                    <span class="rounded-md bg-white px-1.5 py-0.5 font-mono text-2xs text-brand-ink ring-1 ring-brand-ink/10">{{ $tag }}</span>
                @endforeach
            </div>
        @endif

        @if ($jobId !== '')
            <div class="mt-2 flex flex-wrap items-center gap-2">
                @can('update', $site)
                    <x-secondary-button size="xs" type="button" wire:click="revealHorizonPayload({!! \Illuminate\Support\Js::from($jobId) !!})">
                        {{ $payload_uuid === $jobId ? __('Hide payload') : __('Show payload') }}
                    </x-secondary-button>
                @endcan
                @if ($horizonUrl)
                    <a href="{{ $horizonUrl }}" target="_blank" rel="noopener" class="text-xs font-semibold text-brand-forest hover:underline">{{ __('Open in Horizon') }} ↗</a>
                @endif
            </div>

            @if ($payload_uuid !== '' && $payload_uuid === $jobId)
                @php($revealed = $this->revealedPayload())
                <div class="mt-2">
                    @if ($revealed === null && $this->readIsSlow('payload'))
                        <p class="text-2xs text-brand-moss">{{ __('No answer from the server yet. Hide the payload and show it again to retry.') }}</p>
                    @elseif ($revealed === null)
                        <p wire:poll.2s class="flex items-center gap-2 text-2xs text-brand-moss"><x-spinner size="sm" /> {{ __('Reading this job from Horizon…') }}</p>
                    @elseif ($revealed['error'])
                        <p class="text-2xs text-brand-moss">{{ $revealed['error'] }}</p>
                    @else
                        <pre class="max-h-80 overflow-auto rounded-lg border border-brand-ink/10 bg-white p-3 font-mono text-2xs leading-relaxed text-brand-ink">{{ $revealed['payload'] }}</pre>
                    @endif
                </div>
            @endif
        @endif
    </div>
</li>
