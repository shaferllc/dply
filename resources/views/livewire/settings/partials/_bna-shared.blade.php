{{-- Pieces every candidate body needs: the no-org warning, the ?server= /
     ?site= context note, and the quick-add empty state. Kept in one place so
     four variants cannot drift on the states that are easy to forget. --}}
@if (! $currentOrganization)
    <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
        <div class="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-xs text-amber-950">
            {{ __('Select a current organization from the header to load servers and sites as assignment targets.') }}
        </div>
    </div>
@endif

@if ($contextServer || $contextSite)
    <div class="border-b border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5 text-xs text-brand-moss sm:px-4">
        {{ __('Preselected for') }}
        <span class="font-semibold text-brand-ink">{{ $contextServer?->name ?? $contextSite?->name }}</span>
    </div>
@endif
