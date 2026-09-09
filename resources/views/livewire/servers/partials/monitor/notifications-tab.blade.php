@include('livewire.partials.feature-notification-tab', [
    'title' => __('Notification routing'),
    'note' => __('Bind channels to server events — stale metrics and threshold breaches.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry when metrics go stale or a threshold is breached — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for monitor events yet — add one below to get an email or chat message when metrics go stale or a threshold is breached.'),
    'listKey' => 'monitor',
    'notifSubscriptions' => $serverNotifSubscriptions,
    'notifEventLabels' => $serverEventLabels ?? [],
    'settingsHref' => route('servers.notifications', $server),
    'settingsLabel' => __('Manage'),
    'canEdit' => ! $isDeployer,
])
