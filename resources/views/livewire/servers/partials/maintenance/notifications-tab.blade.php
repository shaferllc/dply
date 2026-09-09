@include('livewire.partials.feature-notification-tab', [
    'title' => __('Maintenance alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s maintenance events — when a window is enabled, ended, or auto-ends after its scheduled time. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever a maintenance window is enabled or ended — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for maintenance events yet — add one below to get an email or chat message when a window is enabled or ended.'),
    'listKey' => 'maint',
])
