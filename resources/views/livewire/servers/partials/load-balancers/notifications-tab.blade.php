@include('livewire.partials.feature-notification-tab', [
    'title' => __('Load balancer alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s load-balancer events. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever a load balancer is created or deleted, or a target is added or removed — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for load-balancer events yet — add one below to get an email or chat message when a balancer or target changes.'),
    'listKey' => 'lb',
])
