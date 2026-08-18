@include('livewire.partials.feature-notification-tab', [
    'title' => __('Patch alerts'),
    'note' => __('Route a notification channel (email, Slack, Discord, webhook…) to this server\'s patch events — updates applied, failures, reboots, and automatic-updates changes. Each row binds one channel to one event.'),
    'explainer' => __('Owners and org admins already get an in-app notification (the bell) and inbox entry whenever updates are applied (or fail), the server reboots, or automatic updates are toggled — no setup needed. Add a channel below only to also send email / chat / webhook alerts. (This is separate from the server-level "Automatic updates" monitoring alert.)'),
    'empty' => __('No external channels are routed for patch events yet — add one below to get an email or chat message when updates apply, fail, or the server reboots.'),
    'listKey' => 'patch',
])
