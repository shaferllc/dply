{{-- SERVERLESS > Logs — the third-party drain. It lives in the same stored
     block as the HTTP settings (and saves through the same action), but it is
     a logging decision, so it reads next to the logs rather than on Runtime. --}}
@php
    $features = app(\App\Modules\Serverless\Services\ServerlessFeatureMatrix::class);
    $feature = \App\Modules\Serverless\Contracts\ServerlessFeature::class;
@endphp

@if ($features->siteSupports($site, $feature::LogForwarding))
    <form wire:submit="saveServerlessHttpConfig" class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            class="border-b border-brand-ink/10"
            icon="heroicon-o-arrow-up-on-square"
            :title="__('Forward logs')"
            :note="__('Send the function\'s console and error output to a third-party logging service.')"
        />

        <div class="space-y-3 px-3 py-3 sm:px-4">
            <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <x-input-label for="serverless_log_provider" :value="__('Destination')" />
                <select id="serverless_log_provider" wire:model.live="serverless_log_provider" class="mt-1 block w-full rounded-lg border-brand-ink/15 py-1.5 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                    <option value="">{{ __('Off — logs stay on the platform') }}</option>
                    <option value="papertrail">{{ __('Papertrail') }}</option>
                    <option value="datadog">{{ __('Datadog') }}</option>
                    <option value="logtail">{{ __('Better Stack') }}</option>
                </select>
                <x-input-error :messages="$errors->get('serverless_log_provider')" class="mt-1" />
            </div>

            @if ($serverless_log_provider !== '')
                <div>
                    <x-input-label for="serverless_log_token" :value="$serverless_log_provider === 'datadog' ? __('API key') : __('Source token')" />
                    <x-text-input id="serverless_log_token" type="password" wire:model="serverless_log_token" class="mt-1 block w-full font-mono text-sm" autocomplete="off" />
                    <p class="mt-1 text-2xs text-brand-moss">{{ __('Stored with the function and deployed as its LOG_DESTINATIONS variable.') }}</p>
                    <x-input-error :messages="$errors->get('serverless_log_token')" class="mt-1" />
                </div>
            @endif

            @if ($serverless_log_provider === 'datadog')
                <div class="sm:col-span-2">
                    <x-input-label for="serverless_log_endpoint" :value="__('Intake endpoint')" />
                    <x-text-input id="serverless_log_endpoint" type="text" wire:model="serverless_log_endpoint" class="mt-1 block w-full font-mono text-sm"
                        placeholder="{{ \App\Modules\Serverless\Support\FunctionLogForwarding::DATADOG_DEFAULT_ENDPOINT }}" />
                    <p class="mt-1 text-2xs text-brand-moss">{{ __('Leave blank for the US intake. EU and other regions have their own host.') }}</p>
                    <x-input-error :messages="$errors->get('serverless_log_endpoint')" class="mt-1" />
                </div>
            @endif
            </div>

            <div class="flex justify-end border-t border-brand-ink/10 pt-3">
                <x-primary-button type="submit">
                    <span wire:loading.remove wire:target="saveServerlessHttpConfig">{{ __('Save log drain') }}</span>
                    <span wire:loading wire:target="saveServerlessHttpConfig">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </form>
@endif
