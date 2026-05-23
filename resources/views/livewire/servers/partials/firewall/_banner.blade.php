@if ($applyShowBanner)
    <x-workspace-console-banner
        :status="$applyStatus"
        :message="$applyMessage"
        :subtitle="$applySubtitle"
        :output="$this->applyOutputLines"
        :busy="$applyBusy"
        :dismiss-action="$applyBusy ? null : 'dismissApplyBanner'"
        :poll-action="$applyBusy ? 'pollApplyStatus' : null"
        poll-interval="4s"
        :default-expanded="true"
    />
@endif

<div wire:loading.block wire:target="refreshUfwStatus" class="w-full">
    <x-workspace-console-banner
        status="running"
        :message="__('Reading UFW status from :host …', ['host' => $server->getSshConnectionString()])"
        :subtitle="__('Running ufw status verbose over SSH.')"
        :output="[]"
        :busy="true"
        :default-expanded="false"
        :dismiss-action="null"
    />
</div>
<div wire:loading.block wire:target="runFirewallDiagnostics" class="w-full">
    <x-workspace-console-banner
        status="running"
        :message="__('Running firewall diagnostics on :host …', ['host' => $server->getSshConnectionString()])"
        :subtitle="__('Running ufw status verbose · numbered · ss -ltn · iptables -L INPUT.')"
        :output="[]"
        :busy="true"
        :default-expanded="false"
        :dismiss-action="null"
    />
</div>

@if (! $applyShowBanner && ! empty($panel_event_lines))
    <div wire:loading.remove wire:target="refreshUfwStatus,runFirewallDiagnostics">
        <x-workspace-console-banner
            :status="$panel_event_status"
            :message="$panel_event_message"
            :subtitle="$panelSubtitle"
            :output="$panel_event_lines"
            :busy="false"
            dismiss-action="dismissPanelBanner"
            :default-expanded="true"
        />
    </div>
@endif
