@include('livewire.partials.feature-notification-tab', [
    'title' => __('SSH key alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s SSH-key events. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever an SSH key is added or removed — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for SSH-key events yet — add one below to get an email or chat message when a key is added or removed.'),
    'listKey' => 'ssh',
])
