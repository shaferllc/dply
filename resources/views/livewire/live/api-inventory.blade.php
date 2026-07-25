<div class="contents">
    <x-production-data-banner :connection="$connection" :writes-unlocked="$writesUnlocked">
        <x-slot:actions>
            @if ($apiReady)
                <button type="button" wire:click="refresh" class="rounded-lg bg-amber-950/10 px-3 py-1.5 text-sm font-semibold hover:bg-amber-950/15">
                    {{ __('Refresh') }}
                </button>
            @endif
            <button type="button" wire:click="disconnect" class="rounded-lg bg-amber-950/10 px-3 py-1.5 text-sm font-semibold hover:bg-amber-950/15">
                {{ __('Disconnect') }}
            </button>
        </x-slot:actions>
    </x-production-data-banner>
    <x-production-data-nav :connection="$connection" />

    <x-production-api-inventory
        :title="$title"
        :rows="$rows"
        :error="$error"
        :api-ready="$apiReady"
        :breadcrumbs="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Production'), 'href' => route('live.sites.index'), 'icon' => 'exclamation-triangle'],
            ['label' => $title, 'icon' => 'rectangle-group'],
        ]"
    />
</div>
