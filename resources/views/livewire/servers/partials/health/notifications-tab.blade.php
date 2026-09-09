@include('livewire.partials.feature-notification-tab', [
    'title' => __('Health alerts'),
    'note' => __('Route a channel (email, Slack, Discord, webhook…) to this server’s health cockpit. The cockpit is evaluated on the background health cadence; alerts only fire when overall posture worsens into warning / critical or recovers — not for every standing issue.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry when this server’s health worsens or recovers — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for health events yet — add one below to get an email or chat message when this server’s health degrades.'),
    'listKey' => 'health',
])
