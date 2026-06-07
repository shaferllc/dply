@php
    // Section helpers — kept inline so this view stays self-contained.
    // Each section uses a small icon tile + uppercase eyebrow + heading
    // to give the eye a consistent landing point, instead of seven
    // near-identical two-column cards stacked vertically.
    $tokensCount = $organization->apiTokens->count();
    $webhooksCount = $organization->notificationWebhookDestinations->count();
    $enabledWebhooks = $organization->notificationWebhookDestinations->where('enabled', true)->count();
@endphp

<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="automation">
            <x-livewire-validation-errors />

            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Automation & API'), 'icon' => 'bolt'],
            ]" />

            {{-- Hero card: positioning + at-a-glance stats. Replaces the
                 previous large "intro" card that only carried a doc link. --}}
            <section class="dply-card overflow-hidden">
                <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
                    <div class="lg:col-span-7">
                        <div class="flex items-start gap-3">
                            <x-icon-badge size="md">
                                <x-heroicon-o-bolt class="h-6 w-6" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Organization') }}</p>
                                <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Automation & API') }}</h2>
                                <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                                    {{ __('Notifications, regional defaults, API tokens for CI, and outbound webhooks — everything that fires automatically on behalf of this organization.') }}
                                </p>
                            </div>
                        </div>
                        <div class="mt-4 flex flex-wrap items-center gap-2">
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
                    <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('API tokens') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $tokensCount }}</span>
                                <span class="text-[11px] text-brand-moss">{{ trans_choice('token|tokens', $tokensCount) }}</span>
                            </dd>
                        </div>
                        <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                            <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Webhooks') }}</dt>
                            <dd class="mt-1 flex items-baseline gap-1.5">
                                <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $enabledWebhooks }}</span>
                                <span class="text-[11px] text-brand-moss">/ {{ $webhooksCount }} {{ __('active') }}</span>
                            </dd>
                        </div>
                        <div @class([
                            'rounded-2xl border px-4 py-3 shadow-sm',
                            'border-brand-sage/30 bg-brand-sage/8' => $deploy_email_notifications_enabled,
                            'border-brand-ink/10 bg-white' => ! $deploy_email_notifications_enabled,
                        ])>
                            <dt class="text-[10px] font-semibold uppercase tracking-wide {{ $deploy_email_notifications_enabled ? 'text-brand-forest/80' : 'text-brand-mist' }}">{{ __('Deploy emails') }}</dt>
                            <dd class="mt-1 flex items-center gap-1.5">
                                @if ($deploy_email_notifications_enabled)
                                    <x-heroicon-m-check-circle class="h-4 w-4 shrink-0 text-brand-forest" aria-hidden="true" />
                                    <span class="text-[11px] font-medium text-brand-forest">{{ __('On') }}</span>
                                @else
                                    <x-heroicon-m-no-symbol class="h-4 w-4 shrink-0 text-brand-mist" aria-hidden="true" />
                                    <span class="text-[11px] font-medium text-brand-mist">{{ __('Off') }}</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            </section>

            {{-- Section header partial: icon tile + eyebrow + title + lead.
                 Inlined as a closure-component so all sections share one shape. --}}
            @php
                $sectionHeader = function (string $eyebrow, string $title, string $description, string $icon, string $tone = 'sage') {
                    $tones = [
                        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
                        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
                        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
                        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
                        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
                    ];
                    return [
                        'eyebrow' => $eyebrow,
                        'title' => $title,
                        'description' => $description,
                        'icon' => $icon,
                        'tile' => $tones[$tone] ?? $tones['sage'],
                    ];
                };
            @endphp

            <div class="mt-6 space-y-6">

                {{-- Notifications: deploy emails + credential emails. Merged
                     into one card with a clear toggle stack — they're the same
                     concept ("what do we email about, and to whom"). --}}
                <section class="dply-card overflow-hidden" id="notifications">
                    @php $h = $sectionHeader(__('Notifications'), __('Email defaults'), __('What dply emails about for sites and servers in this organization. Notification routing for channels lives on a separate page.'), 'heroicon-o-bell', 'sage'); @endphp
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-dynamic-component :component="$h['icon']" class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $h['eyebrow'] }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $h['title'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $h['description'] }}</p>
                        </div>
                    </div>
                    <div class="divide-y divide-brand-ink/10">
                        <label class="flex cursor-pointer items-start gap-3 px-6 py-4 transition-colors hover:bg-brand-sand/15 sm:px-7">
                            <input type="checkbox" wire:model.live="deploy_email_notifications_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            <span class="min-w-0 flex-1">
                                <span class="text-sm font-medium text-brand-ink">{{ __('Deploy-finish emails') }}</span>
                                <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('Notify the deployer when a site\'s deploy completes or fails.') }}</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 px-6 py-4 transition-colors hover:bg-brand-sand/15 sm:px-7">
                            <input type="checkbox" wire:model.live="email_server_credentials_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            <span class="min-w-0 flex-1">
                                <span class="text-sm font-medium text-brand-ink">{{ __('Email SSH details when a server finishes provisioning') }}</span>
                                <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('Host, port, and username go to the server creator. The SSH private key stays gated behind the dashboard.') }}</span>
                            </span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 px-6 py-4 transition-colors hover:bg-brand-sand/15 sm:px-7">
                            <input type="checkbox" wire:model.live="email_database_credentials_enabled" class="mt-0.5 h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" />
                            <span class="min-w-0 flex-1">
                                <span class="text-sm font-medium text-brand-ink">{{ __('Email database credentials when created') }}</span>
                                <span class="mt-1 block text-xs leading-relaxed text-brand-moss">{{ __('Includes a plain-text database password when a site is scaffolded or a server database is created in the workspace. Off by default — credentials in mailboxes are an attack surface.') }}</span>
                            </span>
                        </label>
                    </div>
                </section>

                {{-- Cloud alerts: Slack webhook + extra emails for deploy/restart/CPU/memory. --}}
                <section class="dply-card overflow-hidden" id="alerts">
                    @php $h = $sectionHeader(__('Cloud alerts'), __('Alert destinations'), __('Where dply sends deploy-failed, restart, CPU, and memory alerts for Cloud apps. Org owners are always notified on their login emails — these fields add extra recipients.'), 'heroicon-o-exclamation-triangle', 'amber'); @endphp
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
                        <x-icon-badge tone="amber">
                            <x-dynamic-component :component="$h['icon']" class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ $h['eyebrow'] }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $h['title'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $h['description'] }}</p>
                        </div>
                    </div>
                    <form wire:submit="saveAlertDestinations" class="space-y-5 p-6 sm:p-7">
                        <div>
                            <x-input-label for="alert_slack_webhook_url" :value="__('Slack webhook URL')" />
                            <x-text-input id="alert_slack_webhook_url" wire:model="alert_slack_webhook_url" type="url" class="mt-1 block w-full font-mono text-xs" placeholder="https://hooks.slack.com/services/T.../B.../..." />
                            <p class="mt-1 text-xs text-brand-mist">{{ __('Create an Incoming Webhook in your Slack workspace; paste the URL here.') }}</p>
                            <x-input-error :messages="$errors->get('alert_slack_webhook_url')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="alert_extra_emails_input" :value="__('Additional recipient emails')" />
                            <textarea id="alert_extra_emails_input" wire:model="alert_extra_emails_input" rows="3" class="mt-1 block w-full rounded-xl border-brand-ink/15 bg-white font-mono text-xs shadow-sm" placeholder="oncall@example.com&#10;ops@example.com"></textarea>
                            <p class="mt-1 text-xs text-brand-mist">{{ __('One email per line (or comma-separated). Org owners are already included automatically.') }}</p>
                            <x-input-error :messages="$errors->get('alert_extra_emails_input')" class="mt-2" />
                        </div>
                        <div class="flex items-center justify-end">
                            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveAlertDestinations">
                                <span wire:loading.remove wire:target="saveAlertDestinations">{{ __('Save destinations') }}</span>
                                <span wire:loading wire:target="saveAlertDestinations" class="inline-flex items-center gap-2">
                                    <x-spinner size="sm" variant="cream" />
                                    {{ __('Saving…') }}
                                </span>
                            </x-primary-button>
                        </div>
                    </form>
                </section>

                {{-- Edge data region: regional preference for R2 buckets. --}}
                <section class="dply-card overflow-hidden" id="data-region">
                    @php $h = $sectionHeader(__('Data residency'), __('Edge data region'), __('Preferred Cloudflare R2 region for buckets created on behalf of this organization. Existing buckets stay where they are — the setting only applies to future Edge bootstraps.'), 'heroicon-o-globe-europe-africa', 'sky'); @endphp
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-dynamic-component :component="$h['icon']" class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $h['eyebrow'] }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $h['title'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $h['description'] }}</p>
                        </div>
                    </div>
                    <div class="space-y-2 p-6 sm:p-7">
                        <select wire:model.live="edge_data_region" class="block w-full max-w-md rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest">
                            <option value="default">{{ __('Default — Cloudflare picks the region') }}</option>
                            <option value="eu">{{ __('EU — strict EU jurisdiction (R2 EU jurisdiction)') }}</option>
                            <option value="weur">{{ __('Western Europe (weur)') }}</option>
                            <option value="eeur">{{ __('Eastern Europe (eeur)') }}</option>
                            <option value="wnam">{{ __('Western North America (wnam)') }}</option>
                            <option value="enam">{{ __('Eastern North America (enam)') }}</option>
                            <option value="apac">{{ __('Asia-Pacific (apac)') }}</option>
                            <option value="oc">{{ __('Oceania (oc)') }}</option>
                        </select>
                        <p class="text-xs text-brand-mist">{{ __('Selecting "EU" creates buckets in Cloudflare\'s EU jurisdiction — data is stored in the EU and the EU jurisdiction header is set on every request.') }}</p>
                    </div>
                </section>

                {{-- API tokens: create form + sandy "Existing tokens" header + clean rows. --}}
                <section class="dply-card overflow-hidden" id="api-tokens">
                    @php $h = $sectionHeader(__('Automation'), __('API tokens'), __('Scoped tokens for CI/CD and automation. The secret is shown once after creation — copy it into your secrets store immediately.'), 'heroicon-o-key', 'sage'); @endphp
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-dynamic-component :component="$h['icon']" class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $h['eyebrow'] }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $h['title'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $h['description'] }}</p>
                        </div>
                    </div>
                    <form wire:submit="createApiToken" class="space-y-5 p-6 sm:p-7">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div class="sm:col-span-1">
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
                                    class="mt-1 block w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-forest focus:ring-brand-forest"
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
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="text-xs text-brand-mist">{{ __('Deploy scope may receive a default expiry from organization settings.') }}</p>
                            <x-primary-button type="submit">
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Create token') }}
                            </x-primary-button>
                        </div>
                    </form>

                    <div class="border-t border-brand-ink/10 bg-brand-sand/35 px-6 py-2.5 sm:px-7">
                        <p class="text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                            {{ __('Existing tokens') }}
                            @if ($tokensCount > 0)
                                <span class="ms-1 font-mono tabular-nums text-brand-mist">{{ $tokensCount }}</span>
                            @endif
                        </p>
                    </div>
                    @if ($organization->apiTokens->isEmpty())
                        <div class="px-6 py-10 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm text-brand-moss">{{ __('No API tokens yet.') }}</p>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($organization->apiTokens as $apiToken)
                                <li wire:key="org-api-token-{{ $apiToken->id }}" class="flex items-center justify-between gap-4 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7">
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-semibold text-brand-ink">{{ $apiToken->name }}</p>
                                        <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-moss">
                                            <span class="font-mono text-brand-mist">{{ $apiToken->token_prefix }}…</span>
                                            @if ($apiToken->last_used_at)
                                                <span class="text-brand-mist">·</span>
                                                <span>{{ __('Last used :time', ['time' => $apiToken->last_used_at->diffForHumans()]) }}</span>
                                            @endif
                                            @if ($apiToken->expires_at)
                                                <span class="text-brand-mist">·</span>
                                                <span>{{ __('Expires :date', ['date' => $apiToken->expires_at->format('M j, Y')]) }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        wire:click='promptRevokeApiToken({{ json_encode((string) $apiToken->id) }})'
                                        class="shrink-0 text-xs font-medium text-red-600 hover:text-red-700 hover:underline"
                                    >
                                        {{ __('Revoke') }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                {{-- Webhook destinations: outbound Slack/Discord/Teams hooks. --}}
                <section class="dply-card overflow-hidden" id="webhooks">
                    @php $h = $sectionHeader(__('Outbound'), __('Webhook destinations'), __('Push deploy and monitoring events to Slack, Discord, or Microsoft Teams. Scope to one site or fire for every site in this organization.'), 'heroicon-o-link', 'violet'); @endphp
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <x-icon-badge>
                            <x-dynamic-component :component="$h['icon']" class="h-5 w-5" aria-hidden="true" />
                        </x-icon-badge>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $h['eyebrow'] }}</p>
                            <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $h['title'] }}</h3>
                            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ $h['description'] }}</p>
                        </div>
                    </div>
                    <form wire:submit="saveWebhookDestination" class="space-y-4 p-6 sm:p-7">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <x-input-label for="int_hook_name" :value="__('Destination name')" />
                                <x-text-input id="int_hook_name" wire:model="int_hook_name" type="text" class="mt-1 block w-full" placeholder="{{ __('e.g. Ops Slack channel') }}" required />
                            </div>
                            <div>
                                <x-input-label for="int_hook_driver" :value="__('Driver')" />
                                <x-select id="int_hook_driver" wire:model="int_hook_driver" class="mt-1 block w-full">
                                    <option value="slack">Slack</option>
                                    <option value="discord">Discord</option>
                                    <option value="teams">{{ __('Microsoft Teams') }}</option>
                                </x-select>
                            </div>
                        </div>
                        <div>
                            <x-input-label for="int_hook_url" :value="__('Incoming webhook URL')" />
                            <input id="int_hook_url" type="url" wire:model="int_hook_url" required class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white font-mono text-xs shadow-sm" placeholder="https://hooks.slack.com/…" />
                        </div>
                        <div>
                            <x-input-label for="int_hook_site_id" :value="__('Scope')" />
                            <x-select id="int_hook_site_id" wire:model="int_hook_site_id" class="mt-1 block w-full sm:max-w-md">
                                <option value="">{{ __('All sites in this org') }}</option>
                                @foreach ($organization->sites as $s)
                                    <option value="{{ $s->id }}">{{ $s->name }}</option>
                                @endforeach
                            </x-select>
                        </div>

                        <div class="rounded-xl border border-brand-ink/10 bg-brand-cream/40 p-4">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Event subscriptions') }}</p>
                            <div class="mt-3 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                                <div>
                                    <p class="text-[11px] font-medium text-brand-moss">{{ __('Deploys') }}</p>
                                    <div class="mt-1.5 space-y-1.5 text-sm text-brand-ink">
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="int_evt_success" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" /> {{ __('Success') }}</label>
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="int_evt_failed" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" /> {{ __('Failed') }}</label>
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="int_evt_skipped" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" /> {{ __('Skipped') }}</label>
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="int_evt_deploy_started" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" /> {{ __('Started') }}</label>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-[11px] font-medium text-brand-moss">{{ __('Uptime') }}</p>
                                    <div class="mt-1.5 space-y-1.5 text-sm text-brand-ink">
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="int_evt_uptime_down" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" /> {{ __('Monitor down') }}</label>
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="int_evt_uptime_recovered" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" /> {{ __('Monitor recovered') }}</label>
                                    </div>
                                    <p class="mt-3 text-[11px] font-medium text-brand-moss">{{ __('Insights') }} <span class="text-brand-mist">{{ __('(org-wide only)') }}</span></p>
                                    <div class="mt-1.5 space-y-1.5 text-sm text-brand-ink">
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="int_evt_insight_opened" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" /> {{ __('Opened') }}</label>
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="int_evt_insight_resolved" class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest" /> {{ __('Resolved') }}</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <x-primary-button type="submit">
                                <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Add destination') }}
                            </x-primary-button>
                        </div>
                    </form>

                    <div class="border-t border-brand-ink/10 bg-brand-sand/35 px-6 py-2.5 sm:px-7">
                        <p class="text-[0.65rem] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                            {{ __('Saved destinations') }}
                            @if ($webhooksCount > 0)
                                <span class="ms-1 font-mono tabular-nums text-brand-mist">{{ $webhooksCount }}</span>
                            @endif
                        </p>
                    </div>
                    @if ($organization->notificationWebhookDestinations->isEmpty())
                        <div class="px-6 py-10 text-center sm:px-7">
                            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="mt-3 text-sm text-brand-moss">{{ __('No webhook destinations yet.') }}</p>
                        </div>
                    @else
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($organization->notificationWebhookDestinations as $hook)
                                <li wire:key="webhook-{{ $hook->id }}" class="flex items-center justify-between gap-4 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:px-7">
                                    <div class="min-w-0 flex-1">
                                        <p class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                            <span class="truncate text-sm font-semibold text-brand-ink">{{ $hook->name }}</span>
                                            <span class="rounded-md bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-brand-moss">{{ $hook->driver }}</span>
                                            <span @class([
                                                'inline-flex items-center gap-1 text-[11px] font-medium',
                                                'text-brand-forest' => $hook->enabled,
                                                'text-brand-mist' => ! $hook->enabled,
                                            ])>
                                                <span @class([
                                                    'inline-block h-1.5 w-1.5 rounded-full',
                                                    'bg-brand-sage' => $hook->enabled,
                                                    'bg-brand-ink/20' => ! $hook->enabled,
                                                ])></span>
                                                {{ $hook->enabled ? __('On') : __('Off') }}
                                            </span>
                                        </p>
                                        @if ($hook->site_id)
                                            <p class="mt-0.5 text-[11px] text-brand-mist">{{ __('Scoped to site #:id', ['id' => $hook->site_id]) }}</p>
                                        @endif
                                    </div>
                                    <div class="flex shrink-0 items-center gap-3">
                                        <button type="button" wire:click="toggleWebhookDestination('{{ $hook->id }}')" class="text-xs font-medium text-brand-sage hover:text-brand-ink">{{ $hook->enabled ? __('Disable') : __('Enable') }}</button>
                                        <button type="button" wire:click="openConfirmActionModal('deleteWebhookDestination', ['{{ $hook->id }}'], @js(__('Remove webhook destination')), @js(__('Remove this webhook destination?')), @js(__('Remove')), true)" class="text-xs font-medium text-red-600 hover:text-red-700 hover:underline">{{ __('Remove') }}</button>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
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
