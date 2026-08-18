@include('livewire.partials.feature-notification-tab', [
    'title' => __('Error alerts'),
    'note' => __('Route a channel (email, Slack, Discord, webhook…) to this server’s error stream — get pinged when a deploy or operation fails.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry when a deploy or operation fails — no setup needed. Add a channel below only to also send email / chat / webhook alerts. “Deployment failed” covers site deploys; “Server operation failed” covers everything else.'),
    'empty' => __('No external channels are routed for error events yet — add one below to get an email or chat message the moment something fails on this server.'),
    'listKey' => 'errors',
])
