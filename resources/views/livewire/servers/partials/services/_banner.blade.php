@if ($showActionBanner)
    <x-workspace-console-banner
        :status="$systemdActionBannerStatus"
        :message="$actionMessage"
        :subtitle="$actionSubtitle"
        :output="$systemdActionBannerLines"
        :busy="$actionBusy"
        :dismiss-action="$actionBusy ? null : 'dismissSystemdActionBanner'"
        :poll-action="$actionBusy && $systemdRemoteTaskId ? 'syncSystemdRemoteTaskFromCache' : null"
        poll-interval="2s"
        :default-expanded="true"
    />
@endif

@if ($showSyncBanner)
    <x-workspace-console-banner
        :status="$syncBannerStatus"
        :message="$syncBannerMessage"
        :subtitle="$syncBannerSubtitle"
        :output="[]"
        :busy="false"
        dismiss-action="dismissSystemdSyncBanner"
        :default-expanded="false"
    />
@endif
