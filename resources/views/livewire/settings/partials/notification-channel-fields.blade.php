{{-- Expects: $prefix (e.g. new_ or edit_), $type (channel type string) --}}
@php
    $p = $prefix;
@endphp

@if ($type === \App\Models\NotificationChannel::TYPE_SLACK)
    <div class="space-y-4">
        <div>
            <x-input-label for="{{ $p }}slack_webhook_url" :value="__('Webhook URL')" />
            <input
                id="{{ $p }}slack_webhook_url"
                type="url"
                wire:model="{{ $p }}slack_webhook_url"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                placeholder="https://hooks.slack.com/services/…"
                autocomplete="off"
            />
            @error($p.'slack_webhook_url')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}slack_channel" :value="__('Channel (optional)')" />
            <input
                id="{{ $p }}slack_channel"
                type="text"
                wire:model="{{ $p }}slack_channel"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                placeholder="general"
            />
        </div>
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_DISCORD)
    <div>
        <x-input-label for="{{ $p }}discord_webhook_url" :value="__('Webhook URL')" />
        <input
            id="{{ $p }}discord_webhook_url"
            type="url"
            wire:model="{{ $p }}discord_webhook_url"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            placeholder="https://discord.com/api/webhooks/…"
            autocomplete="off"
        />
        @error($p.'discord_webhook_url')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_EMAIL)
    <div>
        <x-input-label for="{{ $p }}email_address" :value="__('E-mail address')" />
        <input
            id="{{ $p }}email_address"
            type="email"
            wire:model="{{ $p }}email_address"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            autocomplete="off"
        />
        @error($p.'email_address')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_TELEGRAM)
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="{{ $p }}telegram_bot_token" :value="__('Bot token')" />
            <input
                id="{{ $p }}telegram_bot_token"
                type="password"
                wire:model="{{ $p }}telegram_bot_token"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="new-password"
            />
            @error($p.'telegram_bot_token')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}telegram_chat_id" :value="__('Chat ID')" />
            <input
                id="{{ $p }}telegram_chat_id"
                type="text"
                wire:model="{{ $p }}telegram_chat_id"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                placeholder="-1001234567890"
            />
            @error($p.'telegram_chat_id')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_PUSHOVER)
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="{{ $p }}pushover_app_token" :value="__('Application token')" />
            <input
                id="{{ $p }}pushover_app_token"
                type="password"
                wire:model="{{ $p }}pushover_app_token"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="off"
            />
            @error($p.'pushover_app_token')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}pushover_user_key" :value="__('User key')" />
            <input
                id="{{ $p }}pushover_user_key"
                type="password"
                wire:model="{{ $p }}pushover_user_key"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                autocomplete="off"
            />
            @error($p.'pushover_user_key')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_MICROSOFT_TEAMS)
    <div>
        <x-input-label for="{{ $p }}teams_webhook_url" :value="__('Incoming webhook URL')" />
        <input
            id="{{ $p }}teams_webhook_url"
            type="url"
            wire:model="{{ $p }}teams_webhook_url"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            autocomplete="off"
        />
        @error($p.'teams_webhook_url')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_ROCKETCHAT)
    <div>
        <x-input-label for="{{ $p }}rocketchat_webhook_url" :value="__('Webhook URL')" />
        <input
            id="{{ $p }}rocketchat_webhook_url"
            type="url"
            wire:model="{{ $p }}rocketchat_webhook_url"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            autocomplete="off"
        />
        @error($p.'rocketchat_webhook_url')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_GOOGLE_CHAT)
    <div>
        <x-input-label for="{{ $p }}google_chat_webhook_url" :value="__('Webhook URL')" />
        <input
            id="{{ $p }}google_chat_webhook_url"
            type="url"
            wire:model="{{ $p }}google_chat_webhook_url"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            autocomplete="off"
        />
        @error($p.'google_chat_webhook_url')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@elseif ($type === \App\Models\NotificationChannel::TYPE_MOBILE_APP)
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="{{ $p }}mobile_device_token" :value="__('Device token')" />
            <textarea
                id="{{ $p }}mobile_device_token"
                wire:model="{{ $p }}mobile_device_token"
                rows="3"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                placeholder="FCM / APNs token"
            ></textarea>
            @error($p.'mobile_device_token')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <x-input-label for="{{ $p }}mobile_platform" :value="__('Platform')" />
            <select
                id="{{ $p }}mobile_platform"
                wire:model="{{ $p }}mobile_platform"
                class="mt-1 block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            >
                <option value="ios">iOS</option>
                <option value="android">Android</option>
            </select>
        </div>
    </div>
@else
    <div>
        <x-input-label for="{{ $p }}webhook_url" :value="__('Endpoint URL')" />
        <input
            id="{{ $p }}webhook_url"
            type="url"
            wire:model="{{ $p }}webhook_url"
            class="mt-1 block w-full rounded-xl border border-brand-ink/15 px-3 py-2 text-sm font-mono shadow-sm focus:border-brand-sage focus:ring-brand-sage"
            placeholder="https://"
            autocomplete="off"
        />
        @error($p.'webhook_url')
            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
        @enderror
    </div>
@endif
