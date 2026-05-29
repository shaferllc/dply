<div>
    <x-page-header
        :title="__('App-wide feature flags')"
        :description="__('Global kill switches and cross-cutting product flags. These are set in config/features.php (via env) and shown here read-only.')"
        flush
        compact
    />

    <div class="space-y-6">
        @foreach ($groups as $group)
            <section class="dply-card-compact">
                <h2 class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ $group['title'] }}</h2>
                <ul class="mt-3 grid gap-2 lg:grid-cols-2">
                    @foreach ($group['flags'] as $flag)
                        <li wire:key="global-flag-{{ $flag['key'] }}">
                            <x-admin-flag-row :flag="$flag" mode="global">
                                <x-admin-flag-state :active="$flag['active']" />
                            </x-admin-flag-row>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</div>
