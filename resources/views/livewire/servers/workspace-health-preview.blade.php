<x-server-workspace-layout
    :server="$server"
    active="health"
    :title="__('Health')"
    :description="__('A live health cockpit for this server — preview what is shipping next.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-workspace-coming-soon
        :server="$server"
        icon="heroicon-o-heart"
        :title="__('Health')"
        :description="__('An at-a-glance cockpit for this server — CPU, memory, disk, services, and certificate health rolled into one status you can trust before you deploy.')"
        :eyebrow="__('Health cockpit preview')"
        :heroNote="__('Live health for :server will surface here when this ships.', ['server' => $server->name])"
        :lines="[
            ['tone' => 'cmd', 'text' => '~ $ dply health'],
            ['tone' => 'muted', 'text' => 'cpu 12%   mem 41%   disk 38%'],
            ['tone' => 'muted', 'text' => 'nginx ok · php-fpm ok · queue ok'],
            ['tone' => 'muted', 'text' => 'tls: 3 valid · next renewal in 41d'],
            ['tone' => 'ok', 'text' => 'overall: healthy'],
        ]"
        :features="[
            ['icon' => 'cpu-chip', 'title' => __('Resource vitals'), 'body' => __('CPU, memory, and disk at a glance, with thresholds that flag pressure early.')],
            ['icon' => 'server-stack', 'title' => __('Service checks'), 'body' => __('Confirms the webserver, PHP-FPM, queue, and scheduler are actually running.')],
            ['icon' => 'lock-closed', 'title' => __('TLS at a glance'), 'body' => __('Surfaces certificate validity and upcoming renewals before they break HTTPS.')],
            ['icon' => 'check-badge', 'title' => __('One status to trust'), 'body' => __('Rolls every check into a single healthy/at-risk verdict before you deploy.')],
        ]"
    />
</x-server-workspace-layout>
