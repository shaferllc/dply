<x-workspace-console-banner
    :status="$bannerStatus"
    :message="$bannerMessage"
    :subtitle="$bannerSubtitle"
    :output="$this->diagnosticsBannerOutputLines"
    :busy="$bannerBusy"
    :dismiss-action="$bannerBusy ? null : 'dismissDiagnosticsBanner'"
    :poll-action="$bannerBusy ? 'syncServicesRemoteTaskFromCache' : null"
    poll-interval="{{ $pollRemoteTaskSeconds }}s"
    :default-expanded="true"
/>
