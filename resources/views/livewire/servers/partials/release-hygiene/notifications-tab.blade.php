@include('livewire.partials.feature-notification-tab', [
    'title' => __('Release hygiene alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s release hygiene — disk pressure, oversized logs, extra release folders, and failed jobs. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry when hygiene findings open — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for release hygiene events yet — add one below to get an email or chat message when hygiene findings open.'),
    'listKey' => 'hygiene',
])
