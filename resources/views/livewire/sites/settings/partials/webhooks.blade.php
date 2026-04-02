<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Deploy webhook') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Configure the site-specific deploy endpoint and its signature requirements here.') }}</p>
    </div>

    <p class="rounded-xl bg-brand-sand/30 p-3 font-mono text-sm break-all text-brand-ink">{{ $deployHookUrl }}</p>

    @if ($revealed_webhook_secret)
        <div>
            <p class="text-sm font-medium text-amber-800">{{ __('Copy your new secret now:') }}</p>
            <pre class="mt-2 overflow-x-auto rounded-xl bg-slate-900 p-3 text-xs text-amber-200">{{ $revealed_webhook_secret }}</pre>
        </div>
    @else
        <p class="text-sm text-brand-moss">{{ __('Secret is stored encrypted. Rotate to see a new one.') }}</p>
    @endif

    <button type="button" wire:click="regenerateWebhookSecret" class="text-sm font-medium text-brand-ink underline">{{ __('Rotate webhook secret') }}</button>

    <form wire:submit="saveWebhookSecurity" class="space-y-3 border-t border-brand-ink/10 pt-4">
        <x-input-label for="webhook_allowed_ips_text" value="Optional IP allow list (one IPv4/IPv6 or IPv4 CIDR per line)" />
        <textarea id="webhook_allowed_ips_text" wire:model="webhook_allowed_ips_text" rows="4" class="w-full rounded-md border-slate-300 shadow-sm font-mono text-xs" placeholder="203.0.113.10&#10;192.0.2.0/24"></textarea>
        <x-input-error :messages="$errors->get('webhook_allowed_ips_text')" class="mt-1" />
        <x-primary-button type="submit">{{ __('Save allow list') }}</x-primary-button>
    </form>
</section>
