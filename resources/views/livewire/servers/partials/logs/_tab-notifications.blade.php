@include('livewire.partials.feature-notification-tab', [
    'title' => __('Log notifications'),
    'note' => __('Route a channel (email, Slack, Discord, webhook…) when a log alert fires. Threshold and pattern rules live on the Alerts tab.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry when a log alert fires — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for log alerts yet — add one below to get an email or chat message when a rule fires.'),
    'listKey' => 'log',
])
