@include('livewire.partials.feature-notification-tab', [
    'title' => __('Snapshot alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s snapshot events — images, database, and cache. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever a snapshot is created, restored, or deleted — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for snapshot events yet — add one below to get an email or chat message when a snapshot is created, restored, or deleted.'),
    'listKey' => 'snap',
])
