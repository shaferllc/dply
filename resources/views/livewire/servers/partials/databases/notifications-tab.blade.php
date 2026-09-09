@include('livewire.partials.feature-notification-tab', [
    'title' => __('Database alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s database events — databases, engines, and users. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever a database is created or removed, an engine is installed or removed, or a database user is created or removed — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for database events yet — add one below to get an email or chat message when a database, user, or engine changes.'),
    'listKey' => 'db',
])
