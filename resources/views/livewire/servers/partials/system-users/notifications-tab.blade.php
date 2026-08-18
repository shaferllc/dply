@include('livewire.partials.feature-notification-tab', [
    'title' => __('System user alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s system-user events. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever a system user is created, updated, or removed — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for system-user events yet — add one below to get an email or chat message when a system user changes.'),
    'listKey' => 'su',
])
