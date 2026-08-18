@include('livewire.partials.feature-notification-tab', [
    'title' => __('Firewall alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s firewall events. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever a firewall rule is created, updated, removed, or applied to the host — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for firewall events yet — add one below to get an email or chat message when a rule changes or rules are applied.'),
    'listKey' => 'fw',
])
