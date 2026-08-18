@include('livewire.partials.feature-notification-tab', [
    'title' => __('Webserver alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s webserver events — engine switches, rollbacks, and config saves. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever the webserver engine is switched (or the switch fails or is reverted) or a config file is saved — no setup needed. Add a channel below only to also send email / chat / webhook alerts.'),
    'empty' => __('No external channels are routed for webserver events yet — add one below to get an email or chat message when the engine changes or a config is saved.'),
    'listKey' => 'ws',
])
