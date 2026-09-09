@include('livewire.partials.feature-notification-tab', [
    'title' => __('Networking alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s networking events. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever networking changes — database/cache exposure, private-network attach/detach, route changes. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for networking events yet — add one below to get an email or chat message when networking changes.'),
    'listKey' => 'net',
])
