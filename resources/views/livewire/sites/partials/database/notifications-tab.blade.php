@include('livewire.partials.feature-notification-tab', [
    'title' => __('Database notifications'),
    'note' => __('Route a channel (email, Slack, Discord, webhook…) to this server\'s database events — created/removed, users, engines, and shared credentials. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry for these events — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for database events yet — add one below to get an email or chat message when a database, user, engine, or credential changes.'),
    'listKey' => 'sitedb',
])
