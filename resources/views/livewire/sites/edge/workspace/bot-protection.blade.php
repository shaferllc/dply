<div>
    <section class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
        @include('livewire.sites.edge.workspace.partials.feature-guide', [
            'docSlug' => 'edge-bot-protection',
            'what' => __('Bot protection uses Cloudflare Turnstile: a privacy-friendly challenge widget so bots can’t submit forms (or browse pages) as easily as real people.'),
            'steps' => [
                __('In Cloudflare → Turnstile, create a widget and copy the Site Key and Secret Key.'),
                __('Paste both keys below, pick Forms only (recommended) or All HTML pages, then enable and Save.'),
                __('Dply republishes delivery — the widget appears on matching traffic within about a minute.'),
            ],
            'setupLinks' => [
                [
                    'label' => __('Open Cloudflare Turnstile'),
                    'href' => 'https://dash.cloudflare.com/?to=/:account/turnstile',
                    'external' => true,
                ],
                [
                    'label' => __('Turnstile docs'),
                    'href' => 'https://developers.cloudflare.com/turnstile/get-started/',
                    'external' => true,
                ],
            ],
            'tips' => [
                __('Site key = public (safe in HTML). Secret key = server-only — never commit it to your frontend repo.'),
                __('Forms only protects POST / form surfaces; use All HTML pages only for site-wide challenges.'),
                __('Pair with Forms → “Require bot check” so Edge form endpoints reject submissions without a valid token.'),
                __('Requires Dply-hosted Edge delivery.'),
            ],
        ])

        @include('livewire.sites.edge.workspace.partials.managed-only-banner', ['managedDelivery' => $managedDelivery])

        <div class="mt-4 space-y-4" @disabled(! $managedDelivery)>
            <label class="flex items-start gap-3">
                <input type="checkbox" wire:model.live="enabled" class="mt-0.5 rounded border-brand-ink/20 text-brand-sage" @disabled(! $managedDelivery) />
                <span>
                    <span class="block text-sm font-medium text-brand-ink">{{ __('Enable bot protection') }}</span>
                    <span class="mt-0.5 block text-xs text-brand-moss">{{ __('When on, Edge injects the challenge on the mode you select below.') }}</span>
                </span>
            </label>

            <div>
                <x-input-label for="mode" :value="__('Where to challenge')" />
                <select id="mode" wire:model="mode" class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm dark:bg-zinc-900" @disabled(! $managedDelivery)>
                    <option value="forms">{{ __('Forms only — contact and signup POSTs') }}</option>
                    <option value="all">{{ __('All HTML pages — every document response') }}</option>
                </select>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Start with Forms only unless you’re under heavy automated browsing.') }}</p>
            </div>

            <div>
                <x-input-label for="site_key" :value="__('Site key (public)')" />
                <x-text-input id="site_key" wire:model="site_key" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="0x4AAAA…" autocomplete="off" @disabled(! $managedDelivery) />
                <p class="mt-1 text-xs text-brand-moss">
                    {{ __('From Cloudflare Turnstile → your widget → Site Key. Embedded in the page; safe to expose.') }}
                    <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer" class="font-medium text-brand-sage underline-offset-2 hover:underline">{{ __('Get keys') }}</a>
                </p>
                <x-input-error :messages="$errors->get('site_key')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="secret_key" :value="__('Secret key')" />
                <x-text-input id="secret_key" wire:model="secret_key" type="password" class="mt-1 block w-full font-mono text-sm" autocomplete="new-password" @disabled(! $managedDelivery) />
                <p class="mt-1 text-xs text-brand-moss">{{ __('From the same Turnstile widget → Secret Key. Used only on Dply-hosted Edge to verify tokens — never put this in your frontend repo.') }}</p>
                <x-input-error :messages="$errors->get('secret_key')" class="mt-2" />
            </div>

            <div class="flex justify-end">
                <x-primary-button type="button" wire:click="save" wire:loading.attr="disabled" @disabled(! $managedDelivery)>
                    <span wire:loading.remove wire:target="save">{{ __('Save') }}</span>
                    <span wire:loading wire:target="save">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </div>
    </section>
</div>
