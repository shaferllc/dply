@props([
    'workspace',
    'active' => 'overview',
    /** @var bool Flags the Overview sidebar item with an amber dot. */
    'needsAttention' => false,
])

@php
    // Per-section title + description shown in the content-column page header.
    // Mirrors the server workspace, where the header reads the active section
    // name and the breadcrumb carries the resource name.
    $sections = [
        'overview' => [
            'label' => __('Overview'),
            'description' => __('At-a-glance health, purpose, and setup for this project.'),
        ],
        'resources' => [
            'label' => __('Resources'),
            'description' => __('The servers and sites grouped under this project.'),
            'help' => [
                'title' => __('How to use resources'),
                'body' => __('Attach every server and site that belongs to this project. Use this tab when you want one shared home for a stack, customer account, product area, or environment cluster. Remove resources when they no longer belong operationally, not just because they are quiet.'),
            ],
        ],
        'access' => [
            'label' => __('Access'),
            'description' => __('Who can operate this project, and at what role.'),
            'help' => [
                'title' => __('How to use access'),
                'body' => __('Keep access here as narrow as possible. Add only the people who should work on this project. Use owners for long-term accountability, maintainers for day-to-day changes, deployers for release execution, and viewers for read-only visibility.'),
            ],
        ],
        'operations' => [
            'label' => __('Operations'),
            'description' => __('Day-two work: activity, health, runbooks, and alert routing.'),
            'help' => [
                'title' => __('How to use operations'),
                'body' => __('This tab is for day-two work: reviewing what changed, seeing whether the grouped resources are healthy, capturing runbooks, and routing the right alerts. Check here first during incident response or before planned maintenance.'),
            ],
        ],
        'delivery' => [
            'label' => __('Delivery'),
            'description' => __('Shared variables and coordinated deploys across the project sites.'),
            'help' => [
                'title' => __('How to use delivery'),
                'body' => __('Use this tab when you want the project to coordinate releases across several sites. Save shared variables here before deploys, then queue one batch when multiple sites should move together.'),
            ],
        ],
    ];
    $current = $sections[$active] ?? $sections['overview'];

    $breadcrumbs = [
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Projects'), 'href' => route('projects.index'), 'icon' => 'rectangle-group'],
        ['label' => $workspace->name, 'href' => route('projects.overview', $workspace), 'icon' => 'folder'],
        ['label' => $current['label']],
    ];
@endphp

<x-project-workspace-shell :workspace="$workspace" :active="$active" :needs-attention="$needsAttention">
    <x-slot:breadcrumb>
        <x-breadcrumb-trail :items="$breadcrumbs" doc-route="docs.index" />
    </x-slot:breadcrumb>

    {{-- Mobile section switcher — the sidebar is hidden below lg. Mirrors the
         server workspace's workspace-mobile-nav <select>. --}}
    <div class="mb-6 lg:hidden">
        <label class="sr-only" for="project-workspace-mobile-nav">{{ __('Section') }}</label>
        <select
            id="project-workspace-mobile-nav"
            class="block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
            onchange="if (this.value && window.Livewire) { window.Livewire.navigate(this.value); }"
        >
            @foreach ($sections as $key => $meta)
                <option value="{{ route('projects.'.$key, $workspace) }}" @selected($active === $key)>{{ $meta['label'] }}</option>
            @endforeach
        </select>
    </div>

    <x-hero-card
        compact
        :title="$current['label']"
        :description="$current['description']"
        icon="rectangle-group"
    >
        @isset($actions)
            <x-slot:topAction>{{ $actions }}</x-slot:topAction>
        @endisset
        @if (! empty($current['help']))
            {{-- Collapsed by default: the guide is reference, not something to
                 read before every visit, and open it pushed the section's own
                 content a full screen-third down. One summary line when shut. --}}
            <x-slot:footer>
                <details class="group -mx-4 -my-4 sm:-mx-5 sm:-my-5">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-2 text-xs font-semibold text-brand-ink hover:bg-brand-sand/20 sm:px-5 [&::-webkit-details-marker]:hidden">
                        <span class="inline-flex min-w-0 items-center gap-1.5">
                            <x-heroicon-m-information-circle class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                            <span class="truncate">{{ $current['help']['title'] }}</span>
                        </span>
                        <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 text-brand-mist transition group-open:rotate-180" aria-hidden="true" />
                    </summary>
                    <p class="border-t border-brand-ink/10 px-4 py-2.5 text-[11px] leading-relaxed text-brand-moss sm:px-5">{{ $current['help']['body'] }}</p>
                </details>
            </x-slot:footer>
        @endif
    </x-hero-card>

    <div class="mt-4 space-y-4">
        {{ $slot }}
    </div>
</x-project-workspace-shell>
