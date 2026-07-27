@props([
    /** @var \App\Support\Projects\ProjectIndexRow $project */
    'project',
])

<li wire:key="project-{{ $project->id }}" class="flex flex-col gap-4 border-b border-brand-ink/10 px-5 py-4 transition-colors last:border-b-0 hover:bg-brand-sand/15 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:gap-6">
    <div class="flex min-w-0 flex-1 items-start gap-4">
        <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-brand-sand/40 text-sm font-bold tracking-tight text-brand-ink shadow-sm ring-1 ring-brand-ink/10" aria-hidden="true">
            <span class="select-none">{{ $project->initials }}</span>
        </span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                @if ($project->manageEnabled && $project->manageHref)
                    <a href="{{ $project->manageHref }}" wire:navigate class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">{{ $project->name }}</a>
                @else
                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $project->name }}</span>
                @endif
                @if ($project->roleLabel)
                    <x-badge size="sm">{{ $project->roleLabel }}</x-badge>
                @endif
                @foreach ($project->labels as $label)
                    <span class="inline-flex items-center rounded-md border border-brand-ink/10 bg-brand-sand/40 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-moss">{{ $label }}</span>
                @endforeach
            </div>
            @if ($project->description)
                <p class="mt-1 max-w-xl text-sm text-brand-moss line-clamp-2">{{ $project->description }}</p>
            @endif
            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-moss">
                <span class="inline-flex items-center gap-1">
                    <x-heroicon-m-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                    <span class="font-mono tabular-nums text-brand-ink">{{ $project->serversCount }}</span>
                    {{ trans_choice('server|servers', $project->serversCount) }}
                </span>
                <span aria-hidden="true" class="text-brand-mist/60">·</span>
                <span class="inline-flex items-center gap-1">
                    <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                    <span class="font-mono tabular-nums text-brand-ink">{{ $project->sitesCount }}</span>
                    {{ trans_choice('site|sites', $project->sitesCount) }}
                </span>
                @if ($project->membersCount > 0 || $project->manageEnabled)
                    <span aria-hidden="true" class="text-brand-mist/60">·</span>
                    <span class="inline-flex items-center gap-1">
                        <x-heroicon-m-user-group class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                        <span class="font-mono tabular-nums text-brand-ink">{{ $project->membersCount }}</span>
                        {{ trans_choice('member|members', $project->membersCount) }}
                    </span>
                @endif
            </div>
        </div>
    </div>
    @if ($project->manageEnabled && $project->manageHref)
        <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
            <a
                href="{{ $project->manageHref }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
            >
                {{ __('Manage') }}
                <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
            </a>
        </div>
    @endif
</li>
