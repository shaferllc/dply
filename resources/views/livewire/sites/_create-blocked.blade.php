@php
    $org = auth()->user()?->currentOrganization();
    $serverOrg = $server->organization;
    $orgMismatch = $serverOrg !== null
        && $org !== null
        && (string) $server->organization_id !== (string) $org->id;
    $planLimit = ! $orgMismatch
        && $org !== null
        && ! $org->userIsDeployer(auth()->user())
        && ! $org->canCreateSite();
@endphp

<div class="mx-auto max-w-2xl">
    <div class="overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-md shadow-brand-ink/5">
        <div class="flex items-start gap-3 border-b border-amber-100 bg-amber-50/80 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-white text-amber-700 ring-1 ring-amber-200">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Cannot create site') }}</p>
                <h1 class="mt-0.5 text-lg font-semibold text-brand-ink">{{ __('Site creation is blocked') }}</h1>
                <p class="mt-2 text-sm leading-relaxed text-brand-moss">{{ $siteCreateBlockedReason }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 px-6 py-5 sm:px-7">
            <a
                href="{{ route('servers.sites', $server) }}"
                wire:navigate
                class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm hover:bg-brand-sand/40"
            >
                <x-heroicon-o-arrow-left class="h-4 w-4" aria-hidden="true" />
                {{ __('Back to sites') }}
            </a>

            @if ($orgMismatch && $serverOrg !== null)
                <a
                    href="{{ route('organizations.index') }}"
                    wire:navigate
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                >
                    {{ __('Switch organization') }}
                </a>
            @elseif ($planLimit && $org !== null)
                <a
                    href="{{ route('billing.show', $org) }}"
                    wire:navigate
                    class="inline-flex items-center gap-2 rounded-lg bg-brand-forest px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-brand-forest/90"
                >
                    {{ __('View billing') }}
                </a>
            @endif
        </div>
    </div>
</div>
