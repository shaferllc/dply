@php
    $title = $statusPage->name.' · ' . config('app.name');
@endphp
<div>
    <div class="border-b border-brand-ink/10 bg-white/90">
        <div class="max-w-3xl mx-auto px-4 py-8">
            <div class="flex items-center gap-3 mb-2">
                <img src="{{ asset('images/dply-logo.svg') }}" alt="" class="h-10 w-auto" width="48" height="54" />
                <div>
                    <h1 class="text-xl font-semibold text-brand-ink">{{ $statusPage->name }}</h1>
                    @if ($statusPage->description)
                        <p class="text-sm text-brand-moss mt-0.5">{{ $statusPage->description }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-3xl mx-auto px-4 py-8 space-y-8">
        @if ($banner === 'operational')
            <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-green-900 text-sm font-medium">
                {{ __('All systems operational') }}
            </div>
        @elseif ($banner === 'degraded')
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-amber-900 text-sm font-medium">
                {{ __('Partial service degradation') }}
            </div>
        @elseif ($banner === 'outage')
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-900 text-sm font-medium">
                {{ __('Service disruption') }}
            </div>
        @elseif (str_starts_with($banner, 'incident'))
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-red-900 text-sm font-medium">
                {{ __('Active incidents') }}
            </div>
        @endif

        <section>
            <h2 class="text-sm font-semibold text-brand-ink uppercase tracking-wide mb-3">{{ __('Components') }}</h2>
            <ul class="rounded-xl border border-brand-ink/10 bg-white divide-y divide-brand-ink/10 shadow-sm">
                @forelse ($rows as $row)
                    @php
                        $st = $row['state'];
                        $dot = match ($st) {
                            \App\Services\Status\MonitorOperationalState::OPERATIONAL => 'bg-green-500',
                            \App\Services\Status\MonitorOperationalState::DEGRADED => 'bg-amber-500',
                            \App\Services\Status\MonitorOperationalState::OUTAGE => 'bg-red-500',
                            default => 'bg-slate-400',
                        };
                    @endphp
                    <li class="flex items-center justify-between gap-3 px-4 py-3">
                        <span class="font-medium text-brand-ink">{{ $row['label'] }}</span>
                        <span class="inline-flex items-center gap-2 text-sm text-brand-moss">
                            <span class="h-2 w-2 rounded-full {{ $dot }}" aria-hidden="true"></span>
                            {{ $resolver->label($st) }}
                        </span>
                    </li>
                @empty
                    <li class="px-4 py-6 text-sm text-brand-moss text-center">{{ __('No monitors configured yet.') }}</li>
                @endforelse
            </ul>
        </section>

        @if ($statusPage->incidents->isNotEmpty())
            <section>
                <h2 class="text-sm font-semibold text-brand-ink uppercase tracking-wide mb-3">{{ __('Incidents') }}</h2>
                <div class="space-y-6">
                    @foreach ($statusPage->incidents as $incident)
                        <article class="rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                            <header class="mb-3">
                                <h3 class="font-semibold text-brand-ink">{{ $incident->title }}</h3>
                                <p class="text-xs text-brand-moss mt-1">
                                    {{ $incident->started_at->toDayDateTimeString() }}
                                    · {{ ucfirst($incident->impact) }}
                                    · {{ ucfirst(str_replace('_', ' ', $incident->state)) }}
                                    @if ($incident->resolved_at)
                                        · {{ __('Resolved :t', ['t' => $incident->resolved_at->toDayDateTimeString()]) }}
                                    @endif
                                </p>
                            </header>
                            <ul class="space-y-3 text-sm text-brand-ink/90">
                                @foreach ($incident->incidentUpdates as $u)
                                    <li class="border-l-2 border-brand-sand pl-3">
                                        <span class="text-xs text-brand-moss">{{ $u->created_at->toDayDateTimeString() }}</span>
                                        <p class="whitespace-pre-wrap mt-0.5">{{ $u->body }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <p class="text-center text-xs text-brand-moss/80 pb-8">
            {{ __('Powered by') }} <span class="font-medium text-brand-ink">{{ config('app.name') }}</span>
        </p>
    </div>
</div>
