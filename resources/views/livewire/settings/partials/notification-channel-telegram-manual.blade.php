{{-- Expects: $p (field prefix).
     The hand-entered Telegram path: a bot the operator created themselves plus a
     chat ID they looked up. Split out of notification-channel-fields so the
     connected-chat branch stays readable, and because self-hosters without a
     dply-registered bot depend on this staying available. --}}
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
