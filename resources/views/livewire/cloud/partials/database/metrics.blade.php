@php
    use App\Modules\Database\Backends\DatabaseBackend;
@endphp

@if (! $capabilities[DatabaseBackend::CAP_METRICS])
    @include('livewire.cloud.partials.database._unavailable', [
        'title' => __('No metrics for this database'),
        'reason' => __('Its backend does not report cluster time series to dply. The provider\'s own dashboard has them.'),
    ])
@else
    <div class="space-y-6">
        <nav class="flex flex-wrap gap-2 text-xs">
            @foreach ($windows as $option)
                <button type="button" wire:click="$set('window', '{{ $option }}')"
                    class="rounded-full border px-3 py-1.5 font-semibold {{ $window === $option ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300' }}">
                    {{ $option }}
                </button>
            @endforeach
        </nav>

        @if ($charts === [])
            <div class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-600 shadow-sm">
                {{ __('Nothing to plot yet — a new cluster takes a few minutes to report its first datapoints.') }}
            </div>
        @else
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($charts as $chart)
                    <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-baseline justify-between gap-4">
                            <h3 class="text-sm font-semibold text-slate-900">{{ $chart['label'] }}</h3>
                            <p class="font-mono text-xs text-slate-500">
                                @if ($chart['latest'] === null)
                                    —
                                @elseif ($chart['format'] === 'percent')
                                    {{ number_format($chart['latest'], 1) }}%
                                @else
                                    {{ number_format($chart['latest'], 2) }}
                                @endif
                            </p>
                        </div>
                        <div class="mt-3">
                            @if ($chart['series'] === [])
                                <p class="py-8 text-center text-xs text-slate-500">{{ __('No datapoints in this window.') }}</p>
                            @else
                                <x-metrics-line-chart
                                    :series="$chart['series']"
                                    :format="$chart['format']"
                                    :y-max="$chart['format'] === 'percent' ? 100 : null" />
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif
