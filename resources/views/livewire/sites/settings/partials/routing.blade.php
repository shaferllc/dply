<section class="rounded-2xl border border-brand-ink/10 bg-white p-3 shadow-sm sm:p-4">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Routing') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('Manage customer domains, aliases, redirects, preview hostnames, and tenant publishing from one routing workspace while keeping certificates separate.') }}</p>
        </div>
        <div class="text-xs text-brand-moss">
            {{ __('Routing updates re-apply the active VM webserver automatically when this site supports managed webserver config.') }}
        </div>
    </div>

    <nav class="mt-4 flex flex-wrap gap-2" aria-label="{{ __('Routing sections') }}">
        @foreach ($routingTabs as $tab)
            <a
                href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => $tab]) }}"
                wire:navigate
                @class([
                    'inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium transition',
                    'bg-slate-100 text-slate-900' => $routingTab === $tab,
                    'border border-slate-200 text-slate-600 hover:bg-slate-50 hover:text-slate-900' => $routingTab !== $tab,
                ])
            >
                <x-dynamic-component :component="$routingTabIcons[$tab] ?? 'heroicon-o-share'" class="h-4 w-4 shrink-0" />
                <span>{{ \Illuminate\Support\Str::headline($tab) }}</span>
            </a>
        @endforeach
    </nav>
</section>

@if ($routingTab === 'domains')
<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Domains') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Manage customer-facing domains only. Preview URLs, aliases, redirects, and tenant hostnames each have their own workspace so routing intent stays explicit.') }}</p>
    </div>

    <ul class="divide-y divide-brand-ink/10">
        @foreach ($site->domains as $domain)
            @php
                $domainHostname = strtolower($domain->hostname);
                $hasSslCoverage = $site->certificates->contains(function ($certificate) use ($domainHostname) {
                    return in_array($certificate->status, [
                        \App\Models\SiteCertificate::STATUS_PENDING,
                        \App\Models\SiteCertificate::STATUS_ISSUED,
                        \App\Models\SiteCertificate::STATUS_INSTALLING,
                        \App\Models\SiteCertificate::STATUS_ACTIVE,
                    ], true)
                        && in_array($domainHostname, $certificate->domainHostnames(), true);
                });
            @endphp
            <li class="flex items-center justify-between gap-3 py-3">
                <div class="min-w-0">
                    <p class="truncate font-mono text-sm text-brand-ink">{{ $domain->hostname }}</p>
                    <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                        @if ($domain->is_primary)
                            <span>{{ __('Primary domain') }}</span>
                        @else
                            <span>{{ __('Additional domain') }}</span>
                        @endif
                        @if ($hasSslCoverage)
                            <span class="rounded-full bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700">{{ __('SSL configured') }}</span>
                        @else
                            <span class="rounded-full bg-amber-50 px-2 py-0.5 font-medium text-amber-700">{{ __('SSL missing') }}</span>
                        @endif
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    @if (! $hasSslCoverage)
                        <button
                            type="button"
                            wire:click="openQuickDomainSslModal('{{ $domain->hostname }}')"
                            class="text-sm font-medium text-brand-sage hover:underline"
                        >
                            {{ __('Add SSL') }}
                        </button>
                    @endif
                    @if (! $domain->is_primary)
                        <button type="button" wire:click="confirmRemoveDomain('{{ $domain->id }}')" class="text-sm font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>

    <form wire:submit="addDomain" class="flex flex-wrap items-end gap-3">
        <div class="min-w-[220px] flex-1">
            <x-input-label for="new_domain_hostname" value="Add domain" />
            <x-text-input id="new_domain_hostname" wire:model="new_domain_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="www.example.com" />
            <x-input-error :messages="$errors->get('new_domain_hostname')" class="mt-1" />
        </div>
        <x-primary-button type="submit">{{ __('Add domain') }}</x-primary-button>
    </form>
</section>
@elseif ($routingTab === 'aliases')
<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Domain aliases') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Aliases extend the web server server_name list for this site. They are not redirects and they are not automatically treated as primary customer domains.') }}</p>
    </div>

    @if ($site->domainAliases->isNotEmpty())
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($site->domainAliases as $alias)
                <li class="flex items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="truncate font-mono text-sm text-brand-ink">{{ $alias->hostname }}</p>
                        <p class="mt-1 text-xs text-brand-moss">{{ $alias->label ?: __('Alias') }}</p>
                    </div>
                    <button type="button" wire:click="removeAlias('{{ $alias->id }}')" class="text-sm font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-sm text-brand-moss">{{ __('No aliases added yet.') }}</p>
    @endif

    <form wire:submit="addAlias" class="grid gap-3 md:grid-cols-[minmax(0,1fr)_16rem_auto] md:items-end">
        <div>
            <x-input-label for="new_alias_hostname" value="Alias hostname" />
            <x-text-input id="new_alias_hostname" wire:model="new_alias_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="www.example.com" />
            <x-input-error :messages="$errors->get('new_alias_hostname')" class="mt-1" />
        </div>
        <div>
            <x-input-label for="new_alias_label" value="Label" />
            <x-text-input id="new_alias_label" wire:model="new_alias_label" class="mt-1 block w-full text-sm" placeholder="Marketing alias" />
        </div>
        <x-primary-button type="submit">{{ __('Add alias') }}</x-primary-button>
    </form>
</section>
@elseif ($routingTab === 'redirects')
<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Redirects') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Keep redirects separate from aliases. Redirects rewrite requests, while aliases only add hostnames to the site config.') }}</p>
    </div>

    @if ($site->redirects->isNotEmpty())
        <ul class="space-y-2 text-sm">
            @foreach ($site->redirects as $redirect)
                <li class="flex items-start justify-between gap-3 rounded-xl border border-brand-ink/10 px-4 py-3">
                    <span class="font-mono text-xs text-brand-ink">{{ $redirect->from_path }} → {{ $redirect->to_url }} ({{ $redirect->status_code }})</span>
                    <button type="button" wire:click="deleteRedirectRule({{ $redirect->id }})" class="shrink-0 text-sm font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                </li>
            @endforeach
        </ul>
    @endif

    <form wire:submit="addRedirectRule" class="flex flex-wrap items-end gap-3">
        <x-text-input wire:model="new_redirect_from" placeholder="/old" class="w-32 font-mono text-sm" />
        <x-text-input wire:model="new_redirect_to" placeholder="https://..." class="min-w-[220px] flex-1 font-mono text-sm" />
        <select wire:model.number="new_redirect_code" class="rounded-md border-slate-300 text-sm">
            <option value="301">301</option>
            <option value="302">302</option>
            <option value="307">307</option>
            <option value="308">308</option>
        </select>
        <x-primary-button type="submit">{{ __('Add redirect') }}</x-primary-button>
    </form>

    @if ($supportsNginxProvisioning)
        <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/60 p-4">
            <p class="text-sm text-brand-moss">{{ __('Routing changes apply automatically after save. Use the manual apply action only if you want to re-run the current webserver config without changing data.') }}</p>
            <div class="mt-3 flex flex-wrap gap-3">
                <button type="button" wire:click="installNginx" wire:loading.attr="disabled" class="inline-flex items-center justify-center gap-2 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50">
                    <span wire:loading.remove wire:target="installNginx">{{ __('Apply webserver config now') }}</span>
                    <span wire:loading wire:target="installNginx">{{ __('Working...') }}</span>
                </button>
            </div>
        </div>
    @endif
</section>
@elseif ($routingTab === 'preview')
<section class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
    <form wire:submit="savePreviewSettings">
        <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <div class="border-b border-brand-ink/10 bg-slate-50/70 p-6 lg:border-b-0 lg:border-r">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Preview domains') }}</h2>
                <p class="mt-3 text-sm leading-6 text-brand-moss">
                    {{ __('Keep preview hostnames separate from customer domains so reachability, auto-SSL, and cleanup stay scoped to testing traffic only.') }}
                </p>
            </div>

            <div class="space-y-5 p-6 sm:p-8">
                <div>
                    <x-input-label for="preview_primary_hostname" value="Primary preview domain" />
                    <x-text-input id="preview_primary_hostname" wire:model="preview_primary_hostname" class="mt-2 block w-full font-mono text-sm" placeholder="preview.example.dply.cc" />
                    <x-input-error :messages="$errors->get('preview_primary_hostname')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="preview_label" value="Label" />
                    <x-text-input id="preview_label" wire:model="preview_label" class="mt-2 block w-full text-sm" />
                    <x-input-error :messages="$errors->get('preview_label')" class="mt-2" />
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 p-4 text-sm text-brand-ink">
                        <input type="checkbox" wire:model="preview_auto_ssl" class="mt-1 rounded border-slate-300 text-brand-ink shadow-sm" />
                        <span>{{ __('Automatically request SSL after the preview domain is reachable.') }}</span>
                    </label>
                    <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 p-4 text-sm text-brand-ink">
                        <input type="checkbox" wire:model="preview_https_redirect" class="mt-1 rounded border-slate-300 text-brand-ink shadow-sm" />
                        <span>{{ __('Redirect preview traffic to HTTPS when a preview certificate is active.') }}</span>
                    </label>
                </div>

                @if ($site->previewDomains->isNotEmpty())
                    <div class="rounded-2xl border border-brand-ink/10 bg-slate-50/50 p-4">
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Known preview domains') }}</p>
                        <ul class="mt-3 space-y-3">
                            @foreach ($site->previewDomains as $previewDomain)
                                <li class="flex items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3">
                                    <div class="min-w-0">
                                        <p class="truncate font-mono text-sm text-brand-ink">{{ $previewDomain->hostname }}</p>
                                        <p class="mt-1 text-xs text-brand-moss">
                                            {{ __('DNS: :dns, SSL: :ssl', ['dns' => $previewDomain->dns_status, 'ssl' => $previewDomain->ssl_status]) }}
                                        </p>
                                    </div>
                                    @if (! $previewDomain->is_primary)
                                        <button type="button" wire:click="removePreviewDomain('{{ $previewDomain->id }}')" class="text-sm font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-slate-50/40 px-6 py-4 sm:px-8">
            <x-primary-button type="submit">{{ __('Save preview settings') }}</x-primary-button>
        </div>
    </form>
</section>
@elseif ($routingTab === 'tenants')
<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Tenant domains') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Tenant domains are published at the web server layer, but your application is still responsible for resolving the tenant from the hostname or tenant key.') }}</p>
    </div>

    @if ($site->tenantDomains->isNotEmpty())
        <ul class="space-y-3">
            @foreach ($site->tenantDomains as $tenantDomain)
                <li class="rounded-xl border border-brand-ink/10 px-4 py-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-mono text-sm text-brand-ink">{{ $tenantDomain->hostname }}</p>
                            <p class="mt-1 text-xs text-brand-moss">
                                {{ $tenantDomain->tenant_key ? __('Tenant key: :key', ['key' => $tenantDomain->tenant_key]) : __('No tenant key recorded') }}
                            </p>
                            @if ($tenantDomain->label || $tenantDomain->notes)
                                <p class="mt-1 text-xs text-brand-moss">{{ $tenantDomain->label }}{{ $tenantDomain->label && $tenantDomain->notes ? ' · ' : '' }}{{ $tenantDomain->notes }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="removeTenantDomain('{{ $tenantDomain->id }}')" class="text-sm font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <p class="text-sm text-brand-moss">{{ __('No tenant domains added yet.') }}</p>
    @endif

    <form wire:submit="addTenantDomain" class="grid gap-3">
        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <x-input-label for="new_tenant_hostname" value="Tenant hostname" />
                <x-text-input id="new_tenant_hostname" wire:model="new_tenant_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="customer.example.com" />
                <x-input-error :messages="$errors->get('new_tenant_hostname')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="new_tenant_key" value="Tenant key" />
                <x-text-input id="new_tenant_key" wire:model="new_tenant_key" class="mt-1 block w-full text-sm" placeholder="customer-acme" />
            </div>
        </div>
        <div class="grid gap-3 md:grid-cols-2">
            <div>
                <x-input-label for="new_tenant_label" value="Label" />
                <x-text-input id="new_tenant_label" wire:model="new_tenant_label" class="mt-1 block w-full text-sm" placeholder="Acme tenant" />
            </div>
            <div>
                <x-input-label for="new_tenant_notes" value="Notes" />
                <x-text-input id="new_tenant_notes" wire:model="new_tenant_notes" class="mt-1 block w-full text-sm" placeholder="App resolver uses hostname mapping" />
            </div>
        </div>
        <div class="flex justify-end">
            <x-primary-button type="submit">{{ __('Add tenant domain') }}</x-primary-button>
        </div>
    </form>
</section>
@endif
