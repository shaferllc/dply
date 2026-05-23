<div wire:loading.block wire:target="syncCronJobs" class="w-full">
    <x-workspace-console-banner
        status="running"
        :message="__('Syncing crontab to :host …', ['host' => $server->getSshConnectionString()])"
        :subtitle="__('Writing the Dply-managed crontab block over SSH.')"
        :output="[]"
        :busy="true"
        :default-expanded="false"
        :dismiss-action="null"
    />
</div>

@if ($panel_event_message !== '')
    <div wire:loading.remove wire:target="syncCronJobs" class="w-full">
        <x-workspace-console-banner
            :status="$panel_event_status"
            :message="$panel_event_message"
            :subtitle="$cronPanelSubtitle"
            :output="$panel_event_lines"
            :busy="$cronPanelBusy"
            dismiss-action="dismissPanelBanner"
            :default-expanded="true"
        />
    </div>
@endif
