@if ($bannerKind !== null)
    <x-workspace-console-banner
        :status="$bannerStatus"
        :message="$bannerMessage"
        :subtitle="$bannerSubtitle"
        :output="$bannerOutput"
        :busy="$bannerBusy"
        :dismiss-action="$bannerDismissAction"
        :poll-action="$bannerBusy ? 'pollSyncStatus' : null"
        poll-interval="4s"
        :default-expanded="$bannerDefaultExpanded"
    />
@endif
