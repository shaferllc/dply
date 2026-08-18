@include('livewire.partials.feature-notification-tab', [
    'title' => __('Insights alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s insights alerts. When a new finding opens (or a resolved issue recurs), subscribed channels get a short message with a link back here.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry when insights findings open or resolve — no setup needed. Add a channel below only to also send email / chat / webhook alerts. Delivery honours your org\'s digest & quiet-hours settings (Settings tab).'),
    'empty' => __('No external channels are routed for insights alerts yet — add one below to get an email or chat message when findings open or recur.'),
    'listKey' => 'insights',
])
