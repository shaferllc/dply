{{-- Compact access-level pill for site workspace page heads. --}}
@if (($headerRoleLabel ?? null) !== null)
    <span
        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] ring-1 ring-inset {{ $headerRoleTone }}"
        title="{{ __('Your access level for this :resource', ['resource' => strtolower($resourceNoun)]) }}"
    >
        @if ($headerIsDeployer)
            <x-heroicon-m-rocket-launch class="h-3 w-3" aria-hidden="true" />
        @elseif ($headerCanUpdateSite)
            <x-heroicon-m-pencil-square class="h-3 w-3" aria-hidden="true" />
        @else
            <x-heroicon-m-eye class="h-3 w-3" aria-hidden="true" />
        @endif
        {{ $headerRoleLabel }}
    </span>
@endif
