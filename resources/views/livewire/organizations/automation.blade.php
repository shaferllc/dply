<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="automation">
            <x-livewire-validation-errors />

            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Automation & API'), 'icon' => 'bolt'],
            ]" />

            <div class="space-y-8">
                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Automation & API') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Deploy email defaults, organization API tokens, and outbound webhooks for deploy and monitoring events.') }}
                            </p>
                        </div>
                        <div class="lg:col-span-8 flex flex-wrap items-start justify-end gap-3">
                            <x-outline-link href="{{ route('docs.index') }}" wire:navigate>
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Documentation') }}
                            </x-outline-link>
                            @can('viewNotificationChannels', $organization)
                                <x-outline-link href="{{ route('organizations.notification-channels', $organization) }}" wire:navigate>
                                    <x-heroicon-o-bell class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                    {{ __('Notification channels') }}
                                </x-outline-link>
                            @endcan
                        </div>
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Deploy emails') }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Whether to send deploy-finish email for sites in this organization. Other notification routing is configured on the channels page.') }}</p>
                        </div>
                        <div class="lg:col-span-8">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" wire:model.live="deploy_email_notifications_enabled" class="mt-1 rounded border-brand-mist text-brand-forest focus:ring-brand-forest" />
                                <span class="text-sm text-brand-ink">{{ __('Send deploy emails for sites in this organization') }}</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('API tokens') }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Create scoped organization tokens for CI/CD and automation. The secret is shown once in a dialog after you create it.') }}</p>
                        </div>
                        <div class="lg:col-span-8 space-y-8 min-w-0">
                            <form wire:submit="createApiToken" class="space-y-5">
                                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div class="sm:col-span-2 lg:col-span-1">
                                        <x-input-label for="token_name" :value="__('Token name')" />
                                        <x-text-input
                                            id="token_name"
                                            type="text"
                                            wire:model="token_name"
                                            class="mt-1 block w-full"
                                            placeholder="{{ __('e.g. GitHub Actions') }}"
                                            required
                                            maxlength="255"
                                            autocomplete="off"
                                        />
                                        @error('token_name')
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div>
                                        <x-input-label for="token_expires_at" :value="__('Expires (optional)')" />
                                        <input
                                            type="date"
                                            id="token_expires_at"
                                            wire:model="token_expires_at"
                                            min="{{ date('Y-m-d', strtotime('+1 day')) }}"
                                            class="mt-1 block w-full rounded-xl border border-brand-mist bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest"
                                        />
                                        @error('token_expires_at')
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div>
                                        <x-input-label for="token_scope" :value="__('Scope')" />
                                        <x-select id="token_scope" wire:model="token_scope" class="mt-1 block w-full">
                                            <option value="full">{{ __('Full access') }}</option>
                                            <option value="read">{{ __('Read only') }}</option>
                                            <option value="deploy">{{ __('Read + deploy') }}</option>
                                            <option value="ops">{{ __('Deploy + ops') }}</option>
                                        </x-select>
                                    </div>
                                </div>
                                <div>
                                    <x-input-label for="token_allowed_ips_text" :value="__('Optional IP allow list')" />
                                    <x-textarea
                                        id="token_allowed_ips_text"
                                        wire:model="token_allowed_ips_text"
                                        rows="3"
                                        class="mt-1 font-mono text-xs"
                                        placeholder="{{ __('Leave empty to allow any IP. One IP or CIDR per line.') }}"
                                    />
                                    @error('token_allowed_ips_text')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-primary-button type="submit" class="!text-sm">{{ __('Create token') }}</x-primary-button>
                                    <p class="text-xs text-brand-mist">{{ __('Deploy scope may receive a default expiry from organization settings.') }}</p>
                                </div>
                            </form>

                            <div class="border-t border-brand-ink/10 pt-6">
                                <p class="text-xs font-semibold uppercase tracking-wider text-brand-moss">{{ __('Existing tokens') }}</p>
                                @if ($organization->apiTokens->isEmpty())
                                    <p class="mt-3 text-sm text-brand-moss">{{ __('No API tokens yet.') }}</p>
                                @else
                                    <ul class="mt-4 space-y-3">
                                        @foreach ($organization->apiTokens as $apiToken)
                                            <li wire:key="org-api-token-{{ $apiToken->id }}" class="rounded-xl border border-brand-mist bg-white p-4 shadow-sm">
                                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                    <div class="min-w-0 space-y-1">
                                                        <p class="font-medium text-brand-ink">{{ $apiToken->name }}</p>
                                                        <p class="font-mono text-xs text-brand-moss">{{ $apiToken->token_prefix }}…</p>
                                                        <div class="flex flex-wrap gap-x-3 gap-y-1 text-xs text-brand-mist">
                                                            @if ($apiToken->last_used_at)
                                                                <span>{{ __('Last used :time', ['time' => $apiToken->last_used_at->diffForHumans()]) }}</span>
                                                            @endif
                                                            @if ($apiToken->expires_at)
                                                                <span>{{ __('Expires :date', ['date' => $apiToken->expires_at->format('M j, Y')]) }}</span>
                                                            @endif
                                                        </div>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        wire:click='promptRevokeApiToken({{ json_encode((string) $apiToken->id) }})'
                                                        class="inline-flex shrink-0 items-center justify-center rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-800 transition-colors hover:bg-red-100"
                                                    >
                                                        {{ __('Revoke') }}
                                                    </button>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                        <div class="lg:col-span-4">
                            <h3 class="text-lg font-semibold text-brand-ink">{{ __('Webhook destinations') }}</h3>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Outbound hooks for deploy and monitoring events to Slack, Discord, or Microsoft Teams.') }}</p>
                        </div>
                        <div class="lg:col-span-8 space-y-6 min-w-0">
                            <form wire:submit="saveWebhookDestination" class="space-y-3 max-w-2xl">
                                <div class="flex flex-wrap gap-2">
                                    <input type="text" wire:model="int_hook_name" placeholder="{{ __('Destination name') }}" required class="min-w-[140px] flex-1 rounded-xl border border-brand-mist px-3 py-2 text-sm shadow-sm" />
                                    <select wire:model="int_hook_driver" class="rounded-xl border border-brand-mist px-3 py-2 text-sm shadow-sm">
                                        <option value="slack">Slack</option>
                                        <option value="discord">Discord</option>
                                        <option value="teams">{{ __('Microsoft Teams') }}</option>
                                    </select>
                                </div>
                                <input type="url" wire:model="int_hook_url" placeholder="{{ __('Incoming webhook URL') }}" required class="w-full rounded-xl border border-brand-mist font-mono text-xs px-3 py-2 shadow-sm" />
                                <div>
                                    <label for="int_hook_site_id" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Limit to one site (optional)') }}</label>
                                    <select id="int_hook_site_id" wire:model="int_hook_site_id" class="w-full max-w-md rounded-xl border border-brand-mist text-sm shadow-sm">
                                        <option value="">{{ __('All sites in this org') }}</option>
                                        @foreach ($organization->sites as $s)
                                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm text-brand-ink">
                                    <span class="w-full text-xs font-medium text-brand-moss">{{ __('Deploy events') }}</span>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_success" class="rounded border-brand-mist" /> {{ __('Success') }}</label>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_failed" class="rounded border-brand-mist" /> {{ __('Failed') }}</label>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_skipped" class="rounded border-brand-mist" /> {{ __('Skipped') }}</label>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_deploy_started" class="rounded border-brand-mist" /> {{ __('Deployment started') }}</label>
                                    <span class="w-full text-xs font-medium text-brand-moss">{{ __('Uptime') }}</span>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_uptime_down" class="rounded border-brand-mist" /> {{ __('Monitor down') }}</label>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_uptime_recovered" class="rounded border-brand-mist" /> {{ __('Monitor recovered') }}</label>
                                    <span class="w-full text-xs font-medium text-brand-moss">{{ __('Insight events (org-wide only)') }}</span>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_insight_opened" class="rounded border-brand-mist" /> {{ __('Opened') }}</label>
                                    <label class="inline-flex items-center gap-2"><input type="checkbox" wire:model="int_evt_insight_resolved" class="rounded border-brand-mist" /> {{ __('Resolved') }}</label>
                                </div>
                                <x-primary-button type="submit" class="!text-sm w-fit">{{ __('Add webhook destination') }}</x-primary-button>
                            </form>

                            @if ($organization->notificationWebhookDestinations->isEmpty())
                                <p class="text-sm text-brand-moss">{{ __('No webhook destinations yet.') }}</p>
                            @else
                                <ul class="divide-y divide-brand-mist/80 rounded-xl border border-brand-mist overflow-hidden bg-white">
                                    @foreach ($organization->notificationWebhookDestinations as $hook)
                                        <li class="flex flex-wrap justify-between gap-2 px-4 py-3 text-sm">
                                            <div>
                                                <span class="font-medium text-brand-ink">{{ $hook->name }}</span>
                                                <span class="ml-2 text-brand-moss">{{ $hook->driver }}</span>
                                                @if ($hook->site_id)
                                                    <span class="ml-2 text-xs text-brand-mist">site #{{ $hook->site_id }}</span>
                                                @endif
                                                <span class="ml-2 text-xs {{ $hook->enabled ? 'text-green-700' : 'text-brand-mist' }}">{{ $hook->enabled ? __('on') : __('off') }}</span>
                                            </div>
                                            <div class="flex gap-2">
                                                <button type="button" wire:click="toggleWebhookDestination('{{ $hook->id }}')" class="text-xs font-medium text-brand-sage hover:text-brand-ink">{{ __('Toggle') }}</button>
                                                <button type="button" wire:click="openConfirmActionModal('deleteWebhookDestination', ['{{ $hook->id }}'], @js(__('Remove webhook destination')), @js(__('Remove this webhook destination?')), @js(__('Remove')), true)" class="text-xs font-medium text-red-600 hover:underline">{{ __('Remove') }}</button>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </x-organization-shell>
    </div>

    @if ($show_new_api_token_modal && $new_token_plaintext)
        @teleport('body')
            <div
                class="fixed inset-0 isolate z-[110] overflow-y-auto"
                role="dialog"
                aria-modal="true"
                aria-labelledby="org-new-api-token-title"
                wire:key="org-new-api-token-dialog"
                x-data
                x-init="
                    document.body.classList.add('overflow-y-hidden');
                    return () => document.body.classList.remove('overflow-y-hidden')
                "
                x-on:keydown.escape.window="$wire.clearNewToken()"
            >
                <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="clearNewToken" aria-hidden="true"></div>
                <div class="relative z-10 flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
                    <div class="relative w-full max-w-lg overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-xl" wire:click.stop>
                        <div class="border-b border-brand-ink/10 px-6 py-5">
                            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('API token created') }}</p>
                            <h2 id="org-new-api-token-title" class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Copy your token now') }}</h2>
                            <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                                {{ __('This secret is shown once. Store it in your password manager or CI secrets—Dply cannot display it again.') }}
                            </p>
                            @if ($new_token_name)
                                <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('Name: :name', ['name' => $new_token_name]) }}</p>
                            @endif
                        </div>
                        <div class="space-y-4 px-6 py-5">
                            <label for="org-new-api-token-value" class="block text-sm font-medium text-brand-ink">{{ __('Secret') }}</label>
                            <textarea
                                id="org-new-api-token-value"
                                readonly
                                rows="4"
                                class="block w-full resize-y rounded-xl border border-brand-ink/15 bg-brand-cream/40 px-3 py-2 font-mono text-xs leading-relaxed text-brand-ink selection:bg-brand-sage/30"
                            >{{ $new_token_plaintext }}</textarea>
                            <div
                                class="flex flex-wrap items-center gap-3"
                                x-data="{
                                    copied: false,
                                    resetTimer: null,
                                    async copySecret() {
                                        try {
                                            await navigator.clipboard.writeText(document.getElementById('org-new-api-token-value').value);
                                            this.copied = true;
                                            clearTimeout(this.resetTimer);
                                            this.resetTimer = setTimeout(() => { this.copied = false }, 2500);
                                        } catch (e) {}
                                    },
                                }"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 min-w-[11rem]"
                                    x-on:click="copySecret()"
                                >
                                    <span x-show="!copied" class="inline-flex items-center gap-2">
                                        <x-heroicon-o-clipboard-document class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                        {{ __('Copy to clipboard') }}
                                    </span>
                                    <span x-show="copied" x-cloak class="inline-flex items-center gap-2 font-medium text-brand-sage">
                                        <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                        {{ __('Copied') }}
                                    </span>
                                </button>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                            <x-primary-button type="button" wire:click="clearNewToken">{{ __('Done') }}</x-primary-button>
                        </div>
                    </div>
                </div>
            </div>
        @endteleport
    @endif

    {{-- Confirm modal must live in the Livewire view tree (not only a layout slot) so state updates and wire: targets bind reliably. --}}
    @include('livewire.partials.confirm-action-modal')
</div>
