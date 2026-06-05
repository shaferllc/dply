<x-server-workspace-layout
    :server="$server"
    active="activity"
    :title="__('Activity')"
    :description="__('A full audit trail of everything that happens on this server — preview what is shipping next.')"
>
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])

    <x-workspace-coming-soon
        :server="$server"
        icon="heroicon-o-clipboard-document-list"
        :title="__('Activity')"
        :description="__('A complete, searchable audit trail for this server — who deployed, who changed a setting, who ran a command — so every action has a name and a timestamp.')"
        :eyebrow="__('Activity log preview')"
        :heroNote="__('Every action on :server will be recorded here when this ships.', ['server' => $server->name])"
        :lines="[
            ['tone' => 'cmd', 'text' => '~ $ dply activity --limit 4'],
            ['tone' => 'muted', 'text' => '12:08  tom    deployed site dply.io'],
            ['tone' => 'muted', 'text' => '11:52  tom    rotated SSH key'],
            ['tone' => 'muted', 'text' => '11:30  ci     ran migration'],
            ['tone' => 'ok', 'text' => '142 events · 30-day retention'],
        ]"
        :features="[
            ['icon' => 'user-circle', 'title' => __('Who did what'), 'body' => __('Every deploy, setting change, and command is attributed to a person or token.')],
            ['icon' => 'magnifying-glass', 'title' => __('Searchable & filterable'), 'body' => __('Filter by actor, action, or time window to answer “what changed?” in seconds.')],
            ['icon' => 'shield-check', 'title' => __('Audit-ready'), 'body' => __('An immutable trail you can hand to a reviewer or attach to an incident.')],
            ['icon' => 'arrow-down-tray', 'title' => __('Exportable'), 'body' => __('Pull the log out for compliance or long-term retention beyond the window.')],
        ]"
    />
</x-server-workspace-layout>
