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
                            <a
                                href="{{ route('docs.index') }}"
                                wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                            >
                                <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                {{ __('Documentation') }}
                            </a>
                            @can('viewNotificationChannels', $organization)
                                <a
                                    href="{{ route('organizations.notification-channels', $organization) }}"
                                    wire:navigate
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-2 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                                >
                                    <x-heroicon-o-bell class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                    {{ __('Notification channels') }}
                                </a>
                            @endcan
                            <x-badge tone="accent" :caps="false" class="text-xs">
                                {{ __('Organization: :name', ['name' => $organization->name]) }}
                            </x-badge>
                        </div>
                    </div>
                </div>

                @if ($new_token_plaintext)
                    <x-alert tone="warning">
                        <p class="mb-1 font-medium text-amber-900">{{ __('API token created: :name', ['name' => $new_token_name]) }}</p>
                        <p class="mb-2 text-sm text-amber-800">{{ __("Copy this token now. It won't be shown again.") }}</p>
                        <code class="block break-all rounded border border-amber-200 bg-white p-3 text-sm select-all">{{ $new_token_plaintext }}</code>
                        <button type="button" wire:click="clearNewToken" class="mt-2 text-sm text-amber-800 underline">{{ __('Dismiss') }}</button>
                    </x-alert>
                @endif

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
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">{{ __('Create scoped organization tokens for CI/CD and automation.') }}</p>
                        </div>
                        <div class="lg:col-span-8 space-y-4 min-w-0">
                            <form wire:submit="createApiToken" class="flex flex-wrap items-end gap-2">
                                <div>
                                    <label for="token_name" class="sr-only">{{ __('Token name') }}</label>
                                    <input type="text" id="token_name" wire:model="token_name" placeholder="{{ __('e.g. GitHub Actions') }}" required maxlength="255" class="rounded-xl border border-brand-mist bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest" />
                                </div>
                                <div>
                                    <label for="token_expires_at" class="sr-only">{{ __('Expires') }}</label>
                                    <input type="date" id="token_expires_at" wire:model="token_expires_at" min="{{ date('Y-m-d', strtotime('+1 day')) }}" class="rounded-xl border border-brand-mist bg-white px-3 py-2 text-sm shadow-sm" />
                                </div>
                                <div>
                                    <label for="token_scope" class="sr-only">{{ __('Scope') }}</label>
                                    <select id="token_scope" wire:model="token_scope" class="rounded-xl border border-brand-mist bg-white px-3 py-2 text-sm shadow-sm">
                                        <option value="full">{{ __('Full access') }}</option>
                                        <option value="read">{{ __('Read only') }}</option>
                                        <option value="deploy">{{ __('Read + deploy') }}</option>
                                        <option value="ops">{{ __('Deploy + ops') }}</option>
                                    </select>
                                </div>
                                <x-primary-button type="submit" class="!text-sm">{{ __('Create token') }}</x-primary-button>
                            </form>
                            <div>
                                <label for="token_allowed_ips_text" class="mb-1 block text-xs font-medium text-brand-moss">{{ __('Optional IP allow list') }}</label>
                                <textarea id="token_allowed_ips_text" wire:model="token_allowed_ips_text" rows="3" class="w-full max-w-xl rounded-xl border border-brand-mist font-mono text-xs shadow-sm focus:border-brand-forest focus:ring-brand-forest" placeholder="{{ __('Leave empty to allow any IP') }}"></textarea>
                                @error('token_allowed_ips_text')
                                    <span class="text-xs text-red-600">{{ $message }}</span>
                                @enderror
                            </div>
                            @if ($organization->apiTokens->isEmpty())
                                <p class="text-sm text-brand-moss">{{ __('No API tokens yet.') }}</p>
                            @else
                                <ul class="divide-y divide-brand-mist/80 rounded-xl border border-brand-mist overflow-hidden bg-white">
                                    @foreach ($organization->apiTokens as $apiToken)
                                        <li class="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                                            <div class="min-w-0">
                                                <span class="font-medium text-brand-ink">{{ $apiToken->name }}</span>
                                                <span class="ml-2 font-mono text-brand-moss">{{ $apiToken->token_prefix }}…</span>
                                                @if ($apiToken->last_used_at)
                                                    <span class="ml-2 text-xs text-brand-mist">{{ __('Last used :time', ['time' => $apiToken->last_used_at->diffForHumans()]) }}</span>
                                                @endif
                                                @if ($apiToken->expires_at)
                                                    <span class="ml-2 text-xs text-brand-mist">{{ __('Expires :date', ['date' => $apiToken->expires_at->format('M j, Y')]) }}</span>
                                                @endif
                                            </div>
                                            <button type="button" wire:click="openConfirmActionModal('revokeApiToken', ['{{ $apiToken->id }}'], @js(__('Revoke API token')), @js(__('Revoke this token? It will stop working immediately.')), @js(__('Revoke')), true)" class="shrink-0 text-sm font-medium text-red-600 hover:underline">{{ __('Revoke') }}</button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
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

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
