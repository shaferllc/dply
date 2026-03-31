<section id="settings-group-keys" class="space-y-4" aria-labelledby="settings-group-keys-title">
    @include('livewire.servers.partials.settings._intro', [
        'headingId' => 'settings-group-keys-title',
        'kicker' => __('Access'),
        'title' => __('SSH keys for this server'),
        'description' => __('The key Dply installs for Git and automation on the guest. It is separate from the encrypted key pair Dply uses to SSH in as your deploy or root user.'),
    ])

    <div id="settings-keys" class="{{ $card }} scroll-mt-24 p-6 sm:p-8" x-data="{ copied: false }">
        <h3 class="text-lg font-semibold text-brand-ink">{{ __('Provisioned key (Git & scripts)') }}</h3>
        <p class="mt-2 text-sm text-brand-moss leading-relaxed">
            {{ __('Add this public key on Git hosts or other services that should trust outbound connections from this server. The matching private key never leaves Dply in plain form.') }}
        </p>
        <div class="mt-6 space-y-4">
            <div>
                <x-input-label value="{{ __('Public key') }}" />
                @if ($serverPub)
                    <div class="mt-1 flex gap-2">
                        <textarea
                            readonly
                            rows="3"
                            class="min-h-[5rem] flex-1 resize-y rounded-lg border border-brand-ink/15 bg-brand-sand/20 px-3 py-2 font-mono text-xs text-brand-ink"
                        >{{ $serverPub }}</textarea>
                        <button
                            type="button"
                            class="h-10 shrink-0 rounded-lg border border-brand-ink/15 bg-white px-3 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                            x-on:click="navigator.clipboard.writeText(@js($serverPub)); copied = true; setTimeout(() => copied = false, 2000)"
                        >
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                        </button>
                    </div>
                @else
                    <p class="mt-2 text-sm text-brand-moss">{{ __('No provisioned key is available yet (SSH may still be provisioning).') }}</p>
                @endif
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 p-4 text-sm text-brand-moss">
                <p class="font-medium text-brand-ink">{{ __('Panel / Dply access') }}</p>
                <p class="mt-1">
                    {{ __('Dply connects using its own stored credentials for your deploy user (and optionally root for some tasks). There is no separate “control panel” public key to copy here.') }}
                </p>
            </div>
        </div>
    </div>
</section>
