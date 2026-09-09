{{--
    Worker-host identity strip. Shown on Overview + Workload so a queue box
    never reads as a pending public site. Expects $workerHost
    ({@see App\Support\Servers\WorkerHostContext}).
--}}
@if ($workerHost->isWorkerHost)
    @php
        $originName = $workerHost->originSite?->name;
        $bannerNote = $originName
            ? __('This box runs queue workers for :site — not a public web front. Scale and destroy workers from the origin site.', ['site' => $originName])
            : __('This box runs queue workers from deployed code — not a public web front.');
    @endphp
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2 border-b border-violet-200/80 bg-violet-50/70 px-3 py-2.5 sm:px-4">
        <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-violet-950">
                <x-heroicon-o-square-3-stack-3d class="h-4 w-4 shrink-0 text-violet-700" aria-hidden="true" />
                {{ __('Worker server') }}
                @if ($originName)
                    <span class="font-medium text-violet-800">· {{ $originName }}</span>
                @endif
            </p>
            <p class="mt-0.5 max-w-3xl text-xs leading-relaxed text-violet-900/85">{{ $bannerNote }}</p>
        </div>
        @if (filled($workerHost->manageUrl) && $workerHost->isSiteSourced)
            <a
                href="{{ $workerHost->manageUrl }}"
                wire:navigate
                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-violet-300 bg-white px-2 text-xs font-semibold text-violet-900 shadow-sm transition hover:bg-violet-100/80"
            >
                {{ __('Worker Servers') }}
                <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            </a>
        @endif
    </div>
@endif
