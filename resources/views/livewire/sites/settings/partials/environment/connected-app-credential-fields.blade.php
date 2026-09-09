{{-- Connected-app credential fields. Expects $appProvider in scope. --}}
@php
    $appCreds = $this->connectedAppCredentialsFor($appProvider);
    $appUsingSaved = ($bindingForm['credential_id'] ?? '') !== '';
@endphp
@if ($appCreds !== [])
    <div>
        <x-input-label for="binding_connected_app_credential" :value="__('Saved credentials')" />
        <div class="mt-1 flex items-center gap-2">
            <select id="binding_connected_app_credential" wire:model.live="bindingForm.credential_id" class="dply-input">
                <option value="">{{ __('Enter keys…') }}</option>
                @foreach ($appCreds as $cred)
                    <option value="{{ $cred['id'] }}">{{ $cred['label'] }}</option>
                @endforeach
            </select>
            @if ($appUsingSaved)
                <button type="button" wire:click="deleteConnectedAppCredential('{{ $bindingForm['credential_id'] }}')" class="inline-flex shrink-0 items-center justify-center rounded-lg border border-rose-200 bg-white px-2.5 py-2 text-rose-700 transition-colors hover:bg-rose-50" title="{{ __('Remove this saved key') }}">
                    <x-heroicon-o-trash class="h-4 w-4" />
                </button>
            @endif
        </div>
    </div>
@endif
@unless ($appUsingSaved)
    @php
        $envPastePlaceholder = match ($appProvider) {
            'discord' => "DISCORD_BOT_TOKEN=…\nDISCORD_WEBHOOK_URL=https://discord.com/api/webhooks/…",
            'telegram' => "TELEGRAM_BOT_TOKEN=…\nTELEGRAM_CHAT_ID=…",
            'google_drive' => "GOOGLE_DRIVE_CLIENT_ID=…\nGOOGLE_DRIVE_CLIENT_SECRET=…\nGOOGLE_DRIVE_REFRESH_TOKEN=…",
            'dropbox' => "DROPBOX_ACCESS_TOKEN=…",
            default => "SLACK_BOT_TOKEN=xoxb-…\nSLACK_WEBHOOK_URL=https://hooks.slack.com/…",
        };
    @endphp
    <div>
        <x-input-label for="binding_app_env_paste" :value="__('Paste .env')" />
        <textarea
            id="binding_app_env_paste"
            wire:model.live.debounce.300ms="bindingForm.env_paste"
            rows="4"
            class="dply-input mt-1 block w-full font-mono text-sm"
            placeholder="{{ $envPastePlaceholder }}"
        ></textarea>
        <p class="mt-1 text-xs text-brand-moss">{{ __('Paste KEY=value lines for this app. Matching fields fill; other keys are ignored.') }}</p>
        @if (filled($connectedAppPasteNote))
            <p class="mt-1 text-xs font-medium text-brand-forest">{{ $connectedAppPasteNote }}</p>
        @endif
    </div>
    @if (in_array($appProvider, ['slack', 'discord', 'telegram'], true))
        <div>
            <x-input-label for="binding_app_bot_token" :value="__('Bot token')" />
            <x-text-input id="binding_app_bot_token" type="password" wire:model="bindingForm.bot_token" class="mt-1 block w-full font-mono text-sm" />
        </div>
    @endif
    @if ($appProvider === 'slack' || $appProvider === 'discord')
        <div>
            <x-input-label for="binding_app_webhook" :value="__('Webhook URL')" />
            <x-text-input id="binding_app_webhook" type="url" wire:model="bindingForm.webhook_url" class="mt-1 block w-full font-mono text-sm" :placeholder="$appProvider === 'slack' ? __('https://hooks.slack.com/…') : __('https://discord.com/api/webhooks/…')" />
            <p class="mt-1 text-xs text-brand-moss">{{ __('Bot token or webhook — at least one.') }}</p>
        </div>
    @endif
    @if ($appProvider === 'slack')
        <div>
            <x-input-label for="binding_app_channel" :value="__('Default channel (optional)')" />
            <x-text-input id="binding_app_channel" wire:model="bindingForm.channel" class="mt-1 block w-full font-mono text-sm" placeholder="#alerts" />
        </div>
    @endif
    @if ($appProvider === 'telegram')
        <div>
            <x-input-label for="binding_app_chat" :value="__('Chat ID (optional)')" />
            <x-text-input id="binding_app_chat" wire:model="bindingForm.chat_id" class="mt-1 block w-full font-mono text-sm" />
        </div>
    @endif
    @if ($appProvider === 'google_drive')
        <div>
            <x-input-label for="binding_app_client_id" :value="__('Client ID')" />
            <x-text-input id="binding_app_client_id" wire:model="bindingForm.client_id" class="mt-1 block w-full font-mono text-sm" />
        </div>
        <div>
            <x-input-label for="binding_app_client_secret" :value="__('Client secret')" />
            <x-text-input id="binding_app_client_secret" type="password" wire:model="bindingForm.client_secret" class="mt-1 block w-full font-mono text-sm" />
        </div>
        <div>
            <x-input-label for="binding_app_refresh" :value="__('Refresh token')" />
            <x-text-input id="binding_app_refresh" type="password" wire:model="bindingForm.refresh_token" class="mt-1 block w-full font-mono text-sm" />
        </div>
        <div>
            <x-input-label for="binding_app_folder" :value="__('Folder ID (optional)')" />
            <x-text-input id="binding_app_folder" wire:model="bindingForm.folder_id" class="mt-1 block w-full font-mono text-sm" />
        </div>
    @endif
    @if ($appProvider === 'dropbox')
        <div>
            <x-input-label for="binding_app_access" :value="__('Access token')" />
            <x-text-input id="binding_app_access" type="password" wire:model="bindingForm.access_token" class="mt-1 block w-full font-mono text-sm" />
        </div>
        <div>
            <x-input-label for="binding_app_key" :value="__('App key (optional)')" />
            <x-text-input id="binding_app_key" wire:model="bindingForm.app_key" class="mt-1 block w-full font-mono text-sm" />
        </div>
        <div>
            <x-input-label for="binding_app_secret" :value="__('App secret (optional)')" />
            <x-text-input id="binding_app_secret" type="password" wire:model="bindingForm.app_secret" class="mt-1 block w-full font-mono text-sm" />
        </div>
    @endif
    <div class="space-y-2">
        <label class="flex items-center gap-2 text-xs font-semibold text-brand-ink">
            <input type="checkbox" wire:model.live="bindingForm.save_credential" class="rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage/40" />
            {{ __('Save these keys for reuse across the team') }}
        </label>
        @if ($bindingForm['save_credential'] ?? false)
            <x-text-input wire:model="bindingForm.credential_name" class="block w-full text-sm" :placeholder="__('Name (optional)')" />
        @endif
    </div>
@endunless
