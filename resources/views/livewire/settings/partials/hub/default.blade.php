{{-- The profile body: four bounded cards in a two-column grid. Replaces a
     single stacked column — the page now reads as four things you can act on
     rather than one continuous scroll, and the danger zone carries its own
     weight instead of being the last stripe of the stack.

     Each card includes a shared block partial, so the controls live in exactly
     one place (partials/hub/_identity, _preferences, _sessions, _danger). --}}
<div class="grid gap-3 p-3 sm:p-4 lg:grid-cols-2">
    <section class="overflow-hidden rounded-xl border border-brand-ink/12 bg-white shadow-sm [&>div]:border-b-0">
        @include('livewire.settings.partials.hub._identity')
    </section>

    <section class="overflow-hidden rounded-xl border border-brand-ink/12 bg-white shadow-sm [&>div]:border-b-0">
        @include('livewire.settings.partials.hub._preferences')
    </section>

    <section class="overflow-hidden rounded-xl border border-brand-ink/12 bg-white shadow-sm [&>div]:border-b-0">
        @include('livewire.settings.partials.hub._sessions')
    </section>

    <section class="overflow-hidden rounded-xl border border-rose-200 bg-white shadow-sm [&>div]:border-b-0">
        @include('livewire.settings.partials.hub._danger')
    </section>
</div>
