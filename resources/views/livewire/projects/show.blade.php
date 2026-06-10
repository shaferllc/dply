<div>
    @php
        // Allowlist guard: $section is already validated in mount(), but never
        // interpolate a request value straight into an @include path.
        $sectionView = in_array($section, ['overview', 'resources', 'access', 'operations', 'delivery'], true)
            ? $section
            : 'overview';
    @endphp

    <x-project-workspace-layout
        :workspace="$workspace"
        :active="$section"
        :needs-attention="$health['issues'] !== []"
    >
        @if (session('success'))
            <x-alert tone="success">{{ session('success') }}</x-alert>
        @endif
        <x-livewire-validation-errors />

        @include('livewire.projects.sections.'.$sectionView)
    </x-project-workspace-layout>

    @include('livewire.partials.confirm-action-modal')
</div>
