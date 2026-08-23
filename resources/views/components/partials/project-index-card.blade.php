@props([
    /** @var \App\Support\Projects\ProjectIndexRow $project */
    'project',
])

{{-- One row per project: name, footprint counts, your role. Description and
     labels live on the project page — the index answers "which project, how
     big, can I open it". --}}
<tr wire:key="project-{{ $project->id }}" class="group border-b border-brand-ink/10 transition-colors last:border-b-0 hover:bg-brand-sand/15">
    <td class="max-w-[20rem] px-3 py-2.5 sm:px-5">
        @if ($project->manageEnabled && $project->manageHref)
            <a href="{{ $project->manageHref }}" wire:navigate class="block truncate font-semibold text-brand-ink transition-colors hover:text-brand-sage" title="{{ $project->name }}">
                {{ $project->name }}
            </a>
        @else
            <span class="block truncate font-semibold text-brand-ink" title="{{ $project->name }}">{{ $project->name }}</span>
        @endif
    </td>

    <td class="whitespace-nowrap px-3 py-2.5 font-mono tabular-nums text-brand-moss sm:px-5">{{ $project->serversCount }}</td>
    <td class="whitespace-nowrap px-3 py-2.5 font-mono tabular-nums text-brand-moss sm:px-5">{{ $project->sitesCount }}</td>
    <td class="hidden whitespace-nowrap px-3 py-2.5 font-mono tabular-nums text-brand-moss sm:table-cell sm:px-5">{{ $project->membersCount }}</td>

    <td class="hidden max-w-[10rem] px-3 py-2.5 text-brand-moss sm:px-5 lg:table-cell">
        <span class="block truncate">{{ $project->roleLabel ?: '—' }}</span>
    </td>

    <td class="px-3 py-2.5 sm:px-5">
        @if ($project->manageEnabled && $project->manageHref)
            <div class="flex items-center justify-end transition-opacity focus-within:opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
                <a
                    href="{{ $project->manageHref }}"
                    wire:navigate
                    class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-ink px-2.5 py-1.5 text-xs font-semibold text-brand-cream transition hover:bg-brand-forest sm:px-3"
                >
                    {{ __('Manage') }}
                    <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                </a>
            </div>
        @endif
    </td>
</tr>
