<x-server-tab-strip
    :tabs="config('server_settings.workspace_tabs', [])"
    :active="$section"
    route-name="servers.settings"
    :route-params="['server' => $server]"
    :aria-label="__('Settings categories')"
/>
