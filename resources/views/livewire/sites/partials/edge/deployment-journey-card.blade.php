<section class="dply-card overflow-hidden">
    <div class="border-b border-brand-ink/10 bg-gradient-to-br from-indigo-50/95 to-white px-6 py-5 sm:px-8">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    @if ($journey['hasFailed'])
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-red-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-red-800 ring-1 ring-red-200">
                            <x-heroicon-s-x-mark class="h-3 w-3" />
                            {{ __('Failed') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-wide text-sky-800 ring-1 ring-sky-200">
                            <x-heroicon-o-arrow-path class="h-3 w-3 animate-spin" />
                            {{ __('Building') }}
                        </span>
                    @endif
                </div>
                <h2 class="mt-2 text-base font-semibold text-brand-ink">
                    {{ __('Edge build (:done/:total)', ['done' => $journey['completedSteps'], 'total' => $journey['totalSteps']]) }}
                </h2>
                <p class="mt-1 text-sm text-brand-moss">
                    {{ __('This view updates live as the build moves through clone, build, and publish.') }}
                </p>
            </div>
            <span class="shrink-0 text-sm font-semibold tabular-nums {{ $journey['hasFailed'] ? 'text-red-700' : 'text-indigo-700' }}">
                {{ $journey['progressPercent'] }}%
            </span>
        </div>

        <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-brand-sand/80">
            <div class="h-full rounded-full {{ $journey['hasFailed'] ? 'bg-red-500' : 'bg-indigo-600' }} transition-[width] duration-300" style="width: {{ $journey['progressPercent'] }}%"></div>
        </div>

        <div class="mt-4 flex items-center gap-3">
            @if ($journey['hasFailed'])
                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-red-600 text-white">
                    <x-heroicon-s-x-mark class="h-4 w-4" aria-hidden="true" />
                </span>
            @else
                <span class="inline-flex h-7 w-7 shrink-0 animate-spin items-center justify-center rounded-full border-[3px] border-indigo-200 border-t-indigo-600" aria-hidden="true"></span>
            @endif
            <p class="text-sm font-semibold text-brand-ink">{{ $journey['currentLabel'] }}</p>
        </div>

        @if ($journey['hasFailed'] && $journey['error'])
            <div class="mt-3 rounded-xl border border-red-300 bg-white/80 px-3 py-2 text-xs">
                <p class="font-semibold uppercase tracking-wide text-red-700">{{ __('Reason') }}</p>
                <p class="mt-1 break-words font-mono leading-5 text-red-900">{{ $journey['error'] }}</p>
            </div>
        @endif
    </div>

    <ol class="divide-y divide-brand-ink/8 px-6 py-2 sm:px-8">
        @foreach ($journey['visibleSteps'] as $key => $label)
            @php
                $loopIndex = array_search($key, $journey['stepKeys'], true);
                $isDone = ! $journey['hasFailed'] && $loopIndex !== false && $loopIndex < $journey['currentStepIndex'];
                $isCurrent = $key === $journey['state'];
            @endphp
            <li class="flex items-start gap-3 py-3">
                <div class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $isCurrent ? ($journey['hasFailed'] ? 'bg-red-600 text-white' : 'bg-indigo-600 text-white ring-4 ring-indigo-100') : ($isDone ? 'bg-emerald-600 text-white' : 'bg-white text-brand-mist ring-1 ring-brand-ink/10') }}">
                    @if ($isDone)
                        <x-heroicon-s-check class="h-3.5 w-3.5" />
                    @elseif ($isCurrent && ! $journey['hasFailed'])
                        <span class="inline-flex h-2.5 w-2.5 animate-pulse rounded-full bg-white"></span>
                    @else
                        {{ $loop->iteration }}
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-sm font-medium text-brand-ink">{{ $label }}</p>
                        @if ($isCurrent && ! $journey['hasFailed'])
                            <span class="rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-indigo-800">{{ __('Live') }}</span>
                        @elseif ($isDone)
                            <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-emerald-800">{{ __('Done') }}</span>
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ol>

    <div class="border-t border-brand-ink/10 bg-brand-sand/15 px-6 py-3 text-right sm:px-8">
        <a
            href="{{ route('sites.edge.deployments.show', ['server' => $server, 'site' => $site, 'deployment' => $deployment, 'tab' => 'log']) }}"
            wire:navigate
            class="text-xs font-medium text-brand-forest hover:underline dark:text-brand-sage"
        >
            {{ __('View build log →') }}
        </a>
    </div>
</section>
