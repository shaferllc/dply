@include('livewire.partials.feature-notification-tab', [
    'title' => __('Certificate alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s certificate events — issued / renewed and renewal failures. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever a certificate is issued / renewed or a renewal fails — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for certificate events yet — add one below to get an email or chat message when a certificate is issued or a renewal fails.'),
    'listKey' => 'cert',
])
