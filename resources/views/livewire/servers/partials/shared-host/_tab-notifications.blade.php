@include('livewire.partials.feature-notification-tab', [
    'title' => __('Shared host alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s shared host alerts. When a site breaks its fairness budget or a critical contention event fires, subscribed channels get a short message with a link back here.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry when shared host alerts fire — no setup needed. Add a channel below only to also send email / chat / webhook alerts. Alerts are deduplicated with a cooldown, and respect the "Send shared host alerts" toggle on the Budgets tab.'),
    'empty' => __('No external channels are routed for shared host alerts yet — add one below to get an email or chat message when a budget or contention alert fires.'),
    'listKey' => 'sh',
])
