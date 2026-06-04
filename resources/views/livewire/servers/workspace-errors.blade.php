<x-server-workspace-layout
    :server="$server"
    active="errors"
    :title="__('Errors')"
    :description="__('Every failure on this server and the sites it hosts — newest first. Dismiss what you’ve handled; retry where supported.')"
>
    <x-explainer>
        <p>{{ __('A dedicated stream of failed operations — deploys, SSL, database/cache engine work, connectivity fixes, uptime checks — captured for this server and everything hosted on it. Like the logs, but only errors.') }}</p>
        <p>{{ __('Dismiss is shared with your team. Retry re-runs the original operation for the categories that support it; otherwise open the error to act at its source.') }}</p>
    </x-explainer>

    @include('livewire.partials.error-stream')
</x-server-workspace-layout>
