{{-- Persistent context banner for wizards opened from /launches/containers/create.
     Reads draft.payload._container_launch via containerLaunchContext() and
     shows the repo + branch the user is provisioning a Docker host for.
     "Change repo" returns to the Containers launcher preserving the wizard
     draft (the launcher re-writes _container_launch on re-inspection). --}}
@if ($containerLaunch)
    @php
        $repoLabel = \Illuminate\Support\Str::after($containerLaunch['repository_url'], 'https://');
        $repoLabel = \Illuminate\Support\Str::beforeLast($repoLabel, '.git') ?: $repoLabel;
    @endphp
    <div class="rounded-2xl border border-sky-200 bg-sky-50/80 px-5 py-4 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <x-heroicon-m-cube-transparent class="mt-0.5 h-5 w-5 shrink-0 text-sky-700" />
                <div class="space-y-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-sky-700">{{ __('Provisioning a Docker host') }}</p>
                    <p class="text-sm font-medium text-brand-ink">
                        {{ $repoLabel }} · <span class="font-mono text-xs text-brand-moss">{{ $containerLaunch['repository_branch'] }}</span>
                        @if ($containerLaunch['repository_subdirectory'] !== '')
                            <span class="font-mono text-xs text-brand-moss">/ {{ $containerLaunch['repository_subdirectory'] }}</span>
                        @endif
                    </p>
                </div>
            </div>
            <a href="{{ route('launches.containers.create') }}" wire:navigate class="text-sm font-medium text-sky-700 underline-offset-2 hover:underline">
                {{ __('Change repo') }} →
            </a>
        </div>
    </div>
@endif
