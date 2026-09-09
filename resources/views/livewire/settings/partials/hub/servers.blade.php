{{-- Servers & sites body: the same card composition as the profile page, but
     this is not a tab of it — these are organization and team defaults, which
     are nobody's personal settings. Your timezone is here because it is what
     schedules, quiet hours, and new servers are stamped with.

     Team defaults get the full width: they carry a team picker plus four
     controls, and halving them put the picker on its own line. --}}
<div class="grid gap-3 p-3 sm:p-4 lg:grid-cols-2">
    <section class="overflow-hidden rounded-xl border border-brand-ink/12 bg-white shadow-sm [&>div]:border-b-0">
        @include('livewire.settings.partials.hub._timezone')
    </section>

    <section class="overflow-hidden rounded-xl border border-brand-ink/12 bg-white shadow-sm [&>div]:border-b-0">
        @include('livewire.settings.partials.hub._org-defaults')
    </section>

    <section class="overflow-hidden rounded-xl border border-brand-ink/12 bg-white shadow-sm [&>div]:border-b-0">
        @include('livewire.settings.partials.hub._insights')
    </section>

    <section class="overflow-hidden rounded-xl border border-brand-ink/12 bg-white shadow-sm lg:col-span-2 [&>div]:border-b-0">
        @include('livewire.settings.partials.hub._team-defaults')
    </section>
</div>
