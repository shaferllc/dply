<x-server-workspace-layout
    :server="$server"
    active="errors"
    :title="__('Errors')"
    :description="__('A dedicated error stream for this server — preview what is shipping next.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-workspace-coming-soon
        :server="$server"
        icon="heroicon-o-exclamation-triangle"
        :title="__('Errors')"
        :description="__('One place for everything that went wrong on this server — failed deploys, provisioning faults, cron failures, and 5xx spikes — captured automatically and grouped so you can fix the cause, not chase logs.')"
        :eyebrow="__('Error stream preview')"
        :heroNote="__('Errors raised on :server will collect here automatically when this ships.', ['server' => $server->name])"
        :lines="[
            ['tone' => 'cmd', 'text' => '~ $ dply errors --since 1h'],
            ['tone' => 'muted', 'text' => 'WHEN   SOURCE        SUMMARY'],
            ['tone' => 'muted', 'text' => '12:04  deploy        composer install exited 1'],
            ['tone' => 'muted', 'text' => '11:47  cron          backup.sh: permission denied'],
            ['tone' => 'ok', 'text' => '2 grouped · 0 unresolved after fix'],
        ]"
        :features="[
            ['icon' => 'inbox-stack', 'title' => __('Everything in one stream'), 'body' => __('Deploys, provisioning, cron, and webserver 5xx — captured without you wiring up logging.')],
            ['icon' => 'square-3-stack-3d', 'title' => __('Grouped by cause'), 'body' => __('Repeated failures collapse into one entry with a count, so noise becomes signal.')],
            ['icon' => 'wrench-screwdriver', 'title' => __('Fix from the error'), 'body' => __('Jump straight from an error to the deploy, cron job, or site that raised it.')],
            ['icon' => 'bell-alert', 'title' => __('Know when it spikes'), 'body' => __('Surface a sudden burst of failures before your users tell you about it.')],
        ]"
    />
</x-server-workspace-layout>
