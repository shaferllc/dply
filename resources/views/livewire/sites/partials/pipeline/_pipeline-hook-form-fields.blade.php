<div class="grid gap-4 sm:grid-cols-2">
    @if ($hook_form_anchor_locked ?? false)
        @include('livewire.sites.partials.pipeline._pipeline-hook-placement-locked')
    @else
        <div>
            <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Type') }}</label>
            <select wire:model.live="new_hook_kind" class="w-full rounded-lg border-brand-ink/15 text-sm">
                @foreach ($deployHookKinds ?? [] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('When') }}</label>
            <select wire:model.live="new_hook_anchor" class="w-full rounded-lg border-brand-ink/15 text-sm">
                @foreach ($deployHookAnchors ?? [] as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        @if ($new_hook_anchor === 'after_step')
            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('After step') }}</label>
                <select wire:model="new_hook_anchor_step_id" class="w-full rounded-lg border-brand-ink/15 text-sm">
                    <option value="">{{ __('Select step…') }}</option>
                    @foreach ($orderedSteps ?? [] as $step)
                        <option value="{{ $step->id }}">{{ $step->pillLabel() }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('new_hook_anchor_step_id')" class="mt-1" />
            </div>
        @endif
    @endif
    <div class="sm:col-span-2">
        <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Label (optional)') }}</label>
        <input type="text" wire:model="new_hook_label" class="w-full rounded-lg border-brand-ink/15 text-sm" />
    </div>
    @if ($new_hook_kind === 'shell')
        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Shell script') }}</label>
            <textarea wire:model="new_hook_script" rows="4" class="w-full rounded-lg border-brand-ink/15 font-mono text-xs"></textarea>
            <x-input-error :messages="$errors->get('new_hook_script')" class="mt-1" />
        </div>
    @elseif ($new_hook_kind === 'webhook')
        <div class="sm:col-span-2">
            <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Webhook URL') }}</label>
            <input type="url" wire:model="new_hook_webhook_url" class="w-full rounded-lg border-brand-ink/15 font-mono text-sm" placeholder="https://..." />
            <x-input-error :messages="$errors->get('new_hook_webhook_url')" class="mt-1" />
        </div>
    @elseif ($new_hook_kind === 'notification')
        <div>
            <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Channel') }}</label>
            <select wire:model="new_hook_notification_channel_id" class="w-full rounded-lg border-brand-ink/15 text-sm">
                <option value="">{{ __('Select channel…') }}</option>
                @foreach ($notificationChannels ?? [] as $channel)
                    <option value="{{ $channel->id }}">{{ $channel->label }} ({{ $channel->type }})</option>
                @endforeach
            </select>
            <x-input-error :messages="$errors->get('new_hook_notification_channel_id')" class="mt-1" />
        </div>
        <div>
            <label class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Notify on') }}</label>
            <select wire:model="new_hook_notification_event" class="w-full rounded-lg border-brand-ink/15 text-sm">
                <option value="site.deployment_started">{{ __('Deploy started') }}</option>
                <option value="site.deployments">{{ __('Deploy finished / failed') }}</option>
            </select>
        </div>
    @endif
</div>
