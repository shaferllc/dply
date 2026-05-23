            <div class="{{ $card }} p-6 sm:p-8 space-y-6">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Advanced') }}</h2>
                <form wire:submit="requestSaveAdvancedSettings" class="space-y-4 max-w-xl">
                    <div class="flex items-start gap-3">
                        <input id="adv_disable" type="checkbox" wire:model.boolean="advanced_disable_sync" class="mt-1 rounded border-brand-ink/20" />
                        <div>
                            <x-input-label for="adv_disable" :value="__('Disable authorized_keys sync (break-glass)')" class="!mb-0" />
                            <p class="text-xs text-brand-moss">{{ __('Blocks automated and dashboard writes until turned off.') }}</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3">
                        <input id="adv_health" type="checkbox" wire:model.boolean="advanced_health_check" class="mt-1 rounded border-brand-ink/20" />
                        <div>
                            <x-input-label for="adv_health" :value="__('Run sshd -t and stat after each sync')" class="!mb-0" />
                            <p class="text-xs text-brand-moss">{{ __('Uses root for sshd -t; deploy user for stat of ~/.ssh/authorized_keys.') }}</p>
                        </div>
                    </div>
                    <div>
                        <x-input-label for="adv_tpl" :value="__('Label template (optional)')" />
                        <x-text-input id="adv_tpl" wire:model="advanced_label_template" class="mt-1 block w-full" placeholder="{name} · {hostname} · {date}" />
                        <p class="mt-1 text-xs text-brand-moss">
                            {{ __('Placeholders: :p.', ['p' => '{name}, {user}, {hostname}, {date}']) }}
                            {{ __('Organization default: set the ssh_key_label_template key in organization server site preferences; per-server meta overrides it.') }}
                        </p>
                    </div>
                    <x-primary-button type="submit" class="!py-2" wire:loading.attr="disabled" wire:target="requestSaveAdvancedSettings,saveAdvancedSettings">
                        <span wire:loading.remove wire:target="requestSaveAdvancedSettings,saveAdvancedSettings">{{ __('Save advanced settings') }}</span>
                        <span wire:loading wire:target="requestSaveAdvancedSettings,saveAdvancedSettings">{{ __('Saving…') }}</span>
                    </x-primary-button>
                </form>
                <p class="text-xs text-brand-moss">{{ __('Outbound webhooks: configure the server “Outbound webhook” URL in Settings to receive JSON when sync completes (signed with your webhook secret).') }}</p>
            </div>
