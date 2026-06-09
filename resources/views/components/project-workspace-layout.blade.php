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
        ],
        'access' => [
            'label' => __('Access'),
            'description' => __('Who can operate this project, and at what role.'),
        ],
        'operations' => [
            'label' => __('Operations'),
            'description' => __('Day-two work: activity, health, runbooks, and alert routing.'),
        ],
        'delivery' => [
            'label' => __('Delivery'),
            'description' => __('Shared variables and coordinated deploys across the project sites.'),
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

    <x-page-header
        :title="$current['label']"
        :description="$current['description']"
        :show-documentation="false"
        flush
    >
        @isset($actions)
            <x-slot name="actions">{{ $actions }}</x-slot>
        @endisset
    </x-page-header>

    <div class="mt-6 space-y-8 sm:mt-8">
        {{ $slot }}
    </div>
</x-project-workspace-shell>
