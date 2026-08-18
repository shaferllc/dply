@include('livewire.partials.feature-notification-tab', [
    'title' => __('Security digest alerts'),
    'note' => __('Route a channel (email, Slack, Discord, webhook…) to this server’s security digest — critical / warning findings and recoveries. Findings are evaluated when the digest is scanned, manually via Refresh and on the daily sweep; alerts only fire when posture worsens into warning / critical or recovers.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry when this server’s security posture worsens or recovers — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for security digest events yet — add one below to get an email or chat message when this server’s posture degrades.'),
    'listKey' => 'sec',
])
