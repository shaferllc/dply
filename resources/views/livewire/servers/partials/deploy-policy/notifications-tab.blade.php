@include('livewire.partials.feature-notification-tab', [
    'title' => __('Deploy window alerts'),
    'note' => __('Route a channel (email, Slack, Discord, webhook…) to deploy-window events — a blocked deploy, or enforcement toggled on / off. Blocked deploys still appear on each site’s Deploys timeline; channels here add email / chat / webhook alerts on top.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry when a deploy is blocked or the policy is toggled — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for deploy window events yet — add one below to get an email or chat message when a deploy is blocked or the policy is toggled.'),
    'listKey' => 'dw',
])
