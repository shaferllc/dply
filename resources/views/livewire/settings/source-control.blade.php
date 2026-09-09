@php
    $providers = $this->providers;
    $totalOAuth = collect($providers)->sum(fn ($p) => $p['accounts']->count());
    $totalPats = collect($providers)->sum(fn ($p) => $p['pats']->count());
    $providersWithAny = collect($providers)
        ->filter(fn ($p) => $p['accounts']->isNotEmpty() || $p['pats']->isNotEmpty())
        ->count();
@endphp

<div>
    <x-livewire-validation-errors />

    @push('breadcrumbs')
        <x-breadcrumb-trail doc-route="docs.markdown" doc-slug="source-control" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
            ['label' => __('Source control'), 'icon' => 'code-bracket-square'],
        ]" />
    @endpush

    <x-profile-shell
        dense
        :title="__('Source control')"
        :description="__('Link GitHub, GitLab, or Bitbucket via OAuth, or paste a personal access token for self-hosted hosts and machine users.')"
        icon="heroicon-o-code-bracket"
    >
        <x-slot:actions>
            {{-- No "Back to profile": the breadcrumb already covers it. --}}
            @if (auth()->user()->currentOrganization())
                <a
                    href="{{ route('credentials.index') }}"
                    wire:navigate
                    class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                >
                    <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                    {{ __('Credentials') }}
                </a>
            @endif
        </x-slot:actions>


        @include($bodyPartial)
    </x-profile-shell>

    {{-- Inside the component root (NOT a layout <x-slot> — layout slots render
         once at page load and never re-render on Livewire updates, so the modal
         could never appear). The partial @teleports itself to <body>. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
