@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
        'rose' => 'bg-rose-50 text-rose-700 ring-rose-200',
    ];
@endphp

{{-- Single stable root: keeps Livewire morph stable if this page ever grows a
     second layout branch again. `display:contents` stays layout-neutral. --}}
<div class="contents">
    <x-server-workspace-layout
        :server="$server"
        active="cron"
        :title="__('Cron jobs')"
        :description="__('Schedule commands in the Dply-managed crontab block for this server.')"
        :context-site="null"
        hide-hero
    >
        @include('livewire.servers.partials.cron._workspace-content')

        <x-slot name="modals">
            @include('livewire.servers.partials.cron._workspace-modals')
        </x-slot>
    </x-server-workspace-layout>
</div>
