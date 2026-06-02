@php
    use App\Models\SiteCertificate;

    $card = 'dply-card overflow-hidden';

    // Helper: does this hostname have an active SSL certificate covering it?
    // Used in the Domains and Aliases lists to show the SSL coverage chip.
    $coversWithSsl = function (string $hostname) use ($site): bool {
        $hostname = strtolower($hostname);

        return $site->certificates->contains(function ($certificate) use ($hostname) {
            return in_array($certificate->status, [
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_INSTALLING,
                SiteCertificate::STATUS_ACTIVE,
            ], true) && in_array($hostname, $certificate->domainHostnames(), true);
        });
    };
@endphp

{{-- Top intro card + standalone tab strip, mirroring the SSH keys workspace:
     a dply-card heading with icon pill, then the shared underlined tablist
     between the intro and the per-tab content cards. --}}
<section class="{{ $card }}">
    <div class="flex flex-col gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
        <div class="flex min-w-0 items-start gap-3">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-share class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ $site->usesDockerRuntime() ? __('Networking') : __('Routing') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $site->usesDockerRuntime() ? __('Inbound + outbound traffic') : __('Domains, DNS, aliases & redirects') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    @if ($site->usesDockerRuntime())
                        {{ __('Manage published hostnames, custom domains, redirects, and preview endpoints from one networking workspace.') }}
                    @else
                        {{ __('Manage customer domains, DNS automation, aliases, redirects, preview hostnames, and tenant publishing from one routing workspace while keeping certificates separate.') }}
                    @endif
                </p>
                <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                    <span class="inline-flex items-center gap-1">
                        <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                        {{ __('Routing changes auto-apply the active webserver config — no manual save needed.') }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</section>

<x-server-workspace-tablist :aria-label="__('Routing sections')" class="mt-6">
    @foreach ($routingTabs as $tab)
        <x-server-workspace-tab
            as="a"
            id="routing-tab-{{ $tab }}"
            :active="$routingTab === $tab"
            :icon="$routingTabIcons[$tab] ?? 'heroicon-o-share'"
            href="{{ route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'routing', 'tab' => $tab]) }}"
            wire:navigate
        >{{ $routingTabLabels[$tab] ?? \Illuminate\Support\Str::headline($tab) }}</x-server-workspace-tab>
    @endforeach
</x-server-workspace-tablist>

@if ($routingTab === 'domains')
    @php $domainCount = $site->domains->count(); @endphp

    {{-- Domains: slim header card with count pill + Add CTA --}}
    <div class="{{ $card }} mt-6">
        <div class="flex flex-col gap-4 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-globe-alt class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Domains') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Customer-facing hostnames. Aliases, redirects, preview, and tenant domains live in their own tabs so routing intent stays explicit.') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ trans_choice('{0} no domains|{1} :count domain|[2,*] :count domains', $domainCount, ['count' => $domainCount]) }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" x-on:click="$dispatch('open-modal', 'add-domain-modal')" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90">
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Add domain') }}
                </button>
            </div>
        </div>
    </div>

    {{-- Add modal: single hostname + comment, plus bulk-paste disclosure --}}
    <x-modal name="add-domain-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Domain') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add a domain') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">{{ __('Add one hostname, or open Bulk import to paste a list from a DNS export.') }}</p>
            <button type="button" x-on:click="$dispatch('close')" class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40" aria-label="{{ __('Close') }}" title="{{ __('Close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="px-6 py-6">
            <form wire:submit="addDomain" id="add-domain-form" class="space-y-4">
                <div>
                    <x-input-label for="new_domain_hostname" :value="__('Hostname')" />
                    <x-text-input id="new_domain_hostname" wire:model="new_domain_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="www.example.com" />
                    <x-input-error :messages="$errors->get('new_domain_hostname')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="new_domain_comment" :value="__('Comment (optional)')" />
                    <textarea id="new_domain_comment" wire:model="new_domain_comment" rows="2" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="{{ __('e.g. EU CDN — primary marketing domain') }}"></textarea>
                    <x-input-error :messages="$errors->get('new_domain_comment')" class="mt-1" />
                </div>
            </form>

            <details class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                        {{ __('Bulk import — paste hostnames') }}
                    </span>
                </summary>
                <form wire:submit="bulkImportDomains" class="mt-3 space-y-3">
                    <div>
                        <x-input-label for="bulk_domain_input" :value="__('One hostname per line')" />
                        <textarea id="bulk_domain_input" wire:model="bulk_domain_input" rows="6" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="example.com&#10;www.example.com&#10;api.example.com&#10;# blank lines and # comments are ignored"></textarea>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Hostnames already present (in any routing table) are silently skipped.') }}</p>
                        <x-input-error :messages="$errors->get('bulk_domain_input')" class="mt-1" />
                    </div>
                    <div class="flex justify-end">
                        <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="bulkImportDomains">
                            <span wire:loading.remove wire:target="bulkImportDomains">{{ __('Import domains') }}</span>
                            <span wire:loading wire:target="bulkImportDomains">{{ __('Importing…') }}</span>
                        </x-secondary-button>
                    </div>
                </form>
            </details>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">{{ __('Auto-applied to the webserver after save.') }}</p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="add-domain-form" wire:loading.attr="disabled" wire:target="addDomain">
                <span wire:loading.remove wire:target="addDomain">{{ __('Add domain') }}</span>
                <span wire:loading wire:target="addDomain">{{ __('Adding…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    {{-- Domains list --}}
    <div class="{{ $card }} mt-6">
        @if ($domainCount === 0)
            <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss"><x-heroicon-o-globe-alt class="h-6 w-6" /></span>
                <p class="text-sm font-medium text-brand-ink">{{ __('No domains yet.') }}</p>
                <p class="text-xs text-brand-moss">{{ __('Add one above so the webserver knows where to listen.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($site->domains as $domain)
                    @php
                        $hasSsl = $coversWithSsl($domain->hostname);
                        $isEditing = $editing_domain_id === (string) $domain->id;
                    @endphp
                    <li class="px-6 py-3 sm:px-8" wire:key="domain-row-{{ $domain->id }}">
                        @if ($isEditing)
                            <form wire:submit="saveEditedDomain" class="space-y-3">
                                <div class="flex flex-wrap items-end gap-3">
                                    <div class="flex-1 min-w-[14rem]">
                                        <x-input-label for="editing_domain_hostname_{{ $domain->id }}" :value="__('Hostname')" />
                                        <x-text-input id="editing_domain_hostname_{{ $domain->id }}" wire:model="editing_domain_hostname" class="mt-1 block w-full font-mono text-sm" />
                                        <x-input-error :messages="$errors->get('editing_domain_hostname')" class="mt-1" />
                                    </div>
                                </div>
                                <div>
                                    <x-input-label for="editing_domain_comment_{{ $domain->id }}" :value="__('Comment (optional)')" />
                                    <textarea id="editing_domain_comment_{{ $domain->id }}" wire:model="editing_domain_comment" rows="2" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"></textarea>
                                    <x-input-error :messages="$errors->get('editing_domain_comment')" class="mt-1" />
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <x-secondary-button type="button" wire:click="cancelEditDomain">{{ __('Cancel') }}</x-secondary-button>
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditedDomain">
                                        <span wire:loading.remove wire:target="saveEditedDomain">{{ __('Save') }}</span>
                                        <span wire:loading wire:target="saveEditedDomain">{{ __('Saving…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sand/40 text-brand-forest ring-brand-ink/10">
                                        <x-heroicon-o-globe-alt class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2 truncate font-mono text-sm font-semibold text-brand-ink">
                                            <span>{{ $domain->hostname }}</span>
                                            @if ($domain->is_primary)
                                                <span class="rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Primary') }}</span>
                                            @endif
                                            @if ($hasSsl)
                                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-800 ring-1 ring-inset ring-emerald-200/70">{{ __('SSL configured') }}</span>
                                            @else
                                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-900 ring-1 ring-inset ring-amber-200/70">{{ __('SSL missing') }}</span>
                                            @endif
                                        </p>
                                        @if ($domain->comment)
                                            <p class="mt-1 whitespace-pre-line text-[11px] italic text-brand-mist"># {{ $domain->comment }}</p>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if (! $hasSsl)
                                        <button type="button" wire:click="openQuickDomainSslModal('{{ $domain->hostname }}')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-lock-closed class="h-3.5 w-3.5" />
                                            {{ __('Add SSL') }}
                                        </button>
                                    @endif
                                    <button type="button" wire:click="editDomain('{{ $domain->id }}')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                        {{ __('Edit') }}
                                    </button>
                                    @if (! $domain->is_primary)
                                        <button type="button" wire:click="confirmRemoveDomain('{{ $domain->id }}')" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                            <x-heroicon-o-trash class="h-4 w-4" />
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-cli-snippet class="mt-6" :commands="[
        ['label' => __('Add'), 'command' => 'dply:site:domain-add '.$site->slug.' new.example.com --primary'],
        ['label' => __('Remove'), 'command' => 'dply:site:domain-remove '.$site->slug.' old.example.com'],
        ['label' => __('Print primary URL'), 'command' => 'dply:site:url '.$site->slug],
        ['label' => __('Find by hostname'), 'command' => 'dply:fleet:domain-find example.com'],
    ]" />

@elseif ($routingTab === 'dns')
    @include('livewire.sites.settings.partials.routing._tab-dns')

@elseif ($routingTab === 'aliases')
    @php $aliasCount = $site->domainAliases->count(); @endphp

    <div class="{{ $card }} mt-6">
        <div class="flex flex-col gap-4 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Domain aliases') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Aliases extend the webserver server_name list. They are not redirects and not automatically primary customer domains.') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ trans_choice('{0} no aliases|{1} :count alias|[2,*] :count aliases', $aliasCount, ['count' => $aliasCount]) }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" x-on:click="$dispatch('open-modal', 'add-alias-modal')" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90">
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Add alias') }}
                </button>
            </div>
        </div>
    </div>

    <x-modal name="add-alias-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Alias') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add an alias') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">{{ __('Adds the hostname to the webserver server_name list. Use bulk import to paste many at once.') }}</p>
            <button type="button" x-on:click="$dispatch('close')" class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40" aria-label="{{ __('Close') }}" title="{{ __('Close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="px-6 py-6">
            <div class="mb-5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-sm leading-6 text-brand-moss">
                <p class="font-medium text-brand-ink">{{ __('What an alias does') }}</p>
                <p class="mt-1">{{ __('An alias makes this site also answer on another hostname, serving the exact same content as your primary domain — dply adds it to the webserver’s server_name list. It does not redirect, and it does not become the primary customer domain.') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>{{ __('Point the hostname’s DNS at this server first (A or CNAME), same as any domain.') }}</li>
                    <li>{{ __('Want www → apex (or any forwarding)? Use the Redirects tab instead — an alias serves content, it doesn’t forward.') }}</li>
                </ul>
                <p class="mt-3 font-medium text-brand-ink">{{ __('Examples') }}</p>
                <ul class="mt-1 space-y-1">
                    <li><code class="font-mono text-xs text-brand-ink">www.example.com</code> — {{ __('serve the site on www as well as the apex') }}</li>
                    <li><code class="font-mono text-xs text-brand-ink">example.net</code> — {{ __('an alternate TLD showing the same site') }}</li>
                </ul>
            </div>
            <form wire:submit="addAlias" id="add-alias-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="new_alias_hostname" :value="__('Hostname')" />
                        <x-text-input id="new_alias_hostname" wire:model="new_alias_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="alt.example.com" />
                        <x-input-error :messages="$errors->get('new_alias_hostname')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="new_alias_label" :value="__('Label (optional)')" />
                        <x-text-input id="new_alias_label" wire:model="new_alias_label" class="mt-1 block w-full text-sm" placeholder="Marketing alias" />
                        <x-input-error :messages="$errors->get('new_alias_label')" class="mt-1" />
                    </div>
                </div>
                <div>
                    <x-input-label for="new_alias_comment" :value="__('Comment (optional)')" />
                    <textarea id="new_alias_comment" wire:model="new_alias_comment" rows="2" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="{{ __('Why this alias exists. Optional.') }}"></textarea>
                    <x-input-error :messages="$errors->get('new_alias_comment')" class="mt-1" />
                </div>
            </form>

            <details class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                        {{ __('Bulk import — paste hostnames') }}
                    </span>
                </summary>
                <form wire:submit="bulkImportAliases" class="mt-3 space-y-3">
                    <div>
                        <x-input-label for="bulk_alias_input" :value="__('One per line: hostname or hostname,label')" />
                        <textarea id="bulk_alias_input" wire:model="bulk_alias_input" rows="6" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="alt.example.com&#10;marketing.example.com,Marketing site"></textarea>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Format: hostname or hostname,label — e.g. www.example.com or example.net,Alt TLD. Existing hostnames are silently skipped.') }}</p>
                        <x-input-error :messages="$errors->get('bulk_alias_input')" class="mt-1" />
                    </div>
                    <div class="flex justify-end">
                        <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="bulkImportAliases">
                            <span wire:loading.remove wire:target="bulkImportAliases">{{ __('Import aliases') }}</span>
                            <span wire:loading wire:target="bulkImportAliases">{{ __('Importing…') }}</span>
                        </x-secondary-button>
                    </div>
                </form>
            </details>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">{{ __('Auto-applied to the webserver after save.') }}</p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="add-alias-form" wire:loading.attr="disabled" wire:target="addAlias">
                <span wire:loading.remove wire:target="addAlias">{{ __('Add alias') }}</span>
                <span wire:loading wire:target="addAlias">{{ __('Adding…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    <div class="{{ $card }} mt-6">
        @if ($aliasCount === 0)
            <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss"><x-heroicon-o-link class="h-6 w-6" /></span>
                <p class="text-sm font-medium text-brand-ink">{{ __('No aliases yet.') }}</p>
                <p class="text-xs text-brand-moss">{{ __('Add one above to extend the webserver server_name list.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($site->domainAliases as $alias)
                    @php
                        $hasSsl = $coversWithSsl($alias->hostname);
                        $isEditing = $editing_alias_id === (string) $alias->id;
                    @endphp
                    <li class="px-6 py-3 sm:px-8" wire:key="alias-row-{{ $alias->id }}">
                        @if ($isEditing)
                            <form wire:submit="saveEditedAlias" class="space-y-3">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <x-input-label :for="'editing_alias_hostname_'.$alias->id" :value="__('Hostname')" />
                                        <x-text-input :id="'editing_alias_hostname_'.$alias->id" wire:model="editing_alias_hostname" class="mt-1 block w-full font-mono text-sm" />
                                        <x-input-error :messages="$errors->get('editing_alias_hostname')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label :for="'editing_alias_label_'.$alias->id" :value="__('Label')" />
                                        <x-text-input :id="'editing_alias_label_'.$alias->id" wire:model="editing_alias_label" class="mt-1 block w-full text-sm" />
                                    </div>
                                </div>
                                <div>
                                    <x-input-label :for="'editing_alias_comment_'.$alias->id" :value="__('Comment (optional)')" />
                                    <textarea :id="'editing_alias_comment_'.$alias->id" wire:model="editing_alias_comment" rows="2" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"></textarea>
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <x-secondary-button type="button" wire:click="cancelEditAlias">{{ __('Cancel') }}</x-secondary-button>
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditedAlias">
                                        <span wire:loading.remove wire:target="saveEditedAlias">{{ __('Save') }}</span>
                                        <span wire:loading wire:target="saveEditedAlias">{{ __('Saving…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sand/40 text-brand-forest ring-brand-ink/10">
                                        <x-heroicon-o-link class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2 truncate font-mono text-sm font-semibold text-brand-ink">
                                            <span>{{ $alias->hostname }}</span>
                                            @if ($alias->label)
                                                <span class="rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ $alias->label }}</span>
                                            @endif
                                            @if ($hasSsl)
                                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-800 ring-1 ring-inset ring-emerald-200/70">{{ __('SSL configured') }}</span>
                                            @else
                                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-amber-900 ring-1 ring-inset ring-amber-200/70">{{ __('SSL missing') }}</span>
                                            @endif
                                        </p>
                                        @if ($alias->comment)
                                            <p class="mt-1 whitespace-pre-line text-[11px] italic text-brand-mist"># {{ $alias->comment }}</p>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if (! $hasSsl)
                                        <button type="button" wire:click="openQuickDomainSslModal('{{ $alias->hostname }}')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-lock-closed class="h-3.5 w-3.5" />
                                            {{ __('Add SSL') }}
                                        </button>
                                    @endif
                                    <button type="button" wire:click="editAlias('{{ $alias->id }}')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                        {{ __('Edit') }}
                                    </button>
                                    <button type="button" wire:click="confirmRemoveAlias('{{ $alias->id }}')" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                        <x-heroicon-o-trash class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-cli-snippet class="mt-6" :commands="[
        ['label' => __('Add'), 'command' => 'dply:site:alias-add '.$site->slug.' alt.example.com --label=Marketing'],
        ['label' => __('Remove'), 'command' => 'dply:site:alias-remove '.$site->slug.' alt.example.com'],
        ['label' => __('List'), 'command' => 'dply:site:alias-list '.$site->slug],
    ]" />

@elseif ($routingTab === 'redirects')
    @php $redirectCount = $site->redirects->count(); @endphp

    <div class="{{ $card }} mt-6">
        <div class="flex flex-col gap-4 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-arrow-uturn-right class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Library') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Redirects') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('HTTP 3xx redirects (browser-visible) and internal rewrites (transparent path remap). Bulk-paste accepts CSV-style rows for the HTTP variant.') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ trans_choice('{0} no rules|{1} :count rule|[2,*] :count rules', $redirectCount, ['count' => $redirectCount]) }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" x-on:click="$dispatch('open-modal', 'add-redirect-modal')" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90">
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Add redirect') }}
                </button>
            </div>
        </div>
    </div>

    <x-modal name="add-redirect-modal" maxWidth="3xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Redirect') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add a redirect') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">{{ __('HTTP redirect or internal rewrite. Use bulk import to paste a list of HTTP redirects.') }}</p>
            <button type="button" x-on:click="$dispatch('close')" class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40" aria-label="{{ __('Close') }}" title="{{ __('Close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="px-6 py-6">
            <form wire:submit="addRedirectRule" id="add-redirect-form" class="space-y-4">
                <div class="grid gap-3 sm:grid-cols-[10rem_1fr_1fr_8rem]">
                    <div>
                        <x-input-label for="new_redirect_kind" :value="__('Kind')" />
                        <select id="new_redirect_kind" wire:model.live="new_redirect_kind" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                            <option value="http">{{ __('HTTP redirect') }}</option>
                            <option value="internal_rewrite">{{ __('Internal rewrite') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="new_redirect_from" :value="__('From')" />
                        <x-text-input id="new_redirect_from" wire:model="new_redirect_from" class="mt-1 block w-full font-mono text-sm" placeholder="/old" />
                        <x-input-error :messages="$errors->get('new_redirect_from')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="new_redirect_to" :value="$new_redirect_kind === 'internal_rewrite' ? __('To path') : __('Destination')" />
                        <x-text-input id="new_redirect_to" wire:model="new_redirect_to" class="mt-1 block w-full font-mono text-sm" :placeholder="$new_redirect_kind === 'internal_rewrite' ? '/new' : 'https://example.com'" />
                        <x-input-error :messages="$errors->get('new_redirect_to')" class="mt-1" />
                    </div>
                    @if ($new_redirect_kind === 'http')
                        <div>
                            <x-input-label for="new_redirect_code" :value="__('Status')" />
                            <select id="new_redirect_code" wire:model.number="new_redirect_code" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                                <option value="301">301</option>
                                <option value="302">302</option>
                                <option value="303">303</option>
                                <option value="307">307</option>
                                <option value="308">308</option>
                            </select>
                        </div>
                    @endif
                </div>
                @if ($new_redirect_kind === 'http')
                    <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                        <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-brand-mist">
                            <span class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                                {{ __('Response headers (optional)') }}
                            </span>
                        </summary>
                        <p class="mt-2 text-xs text-brand-moss">{{ __('Sent with the redirect on nginx, Apache, Caddy. OpenLiteSpeed applies the redirect but ignores these headers — set them at the app layer if needed.') }}</p>
                        @foreach ($new_redirect_header_rows as $idx => $row)
                            <div class="mt-3 flex flex-wrap items-end gap-2">
                                <div class="flex-1 min-w-[10rem]">
                                    <x-input-label :for="'hdr_name_'.$idx" :value="__('Name')" />
                                    <x-text-input :id="'hdr_name_'.$idx" wire:model="new_redirect_header_rows.{{ $idx }}.name" class="mt-1 w-full font-mono text-xs" placeholder="X-Robots-Tag" />
                                    <x-input-error :messages="$errors->get('new_redirect_header_rows.'.$idx.'.name')" class="mt-1" />
                                </div>
                                <div class="flex-[2] min-w-[14rem]">
                                    <x-input-label :for="'hdr_val_'.$idx" :value="__('Value')" />
                                    <x-text-input :id="'hdr_val_'.$idx" wire:model="new_redirect_header_rows.{{ $idx }}.value" class="mt-1 w-full font-mono text-xs" placeholder="noindex" />
                                    <x-input-error :messages="$errors->get('new_redirect_header_rows.'.$idx.'.value')" class="mt-1" />
                                </div>
                                @if (count($new_redirect_header_rows) > 1)
                                    <button type="button" wire:click="removeNewRedirectHeaderRow({{ $idx }})" class="mb-1 text-xs font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                                @endif
                            </div>
                        @endforeach
                        @if (count($new_redirect_header_rows) < 8)
                            <button type="button" wire:click="addNewRedirectHeaderRow" class="mt-3 text-xs font-semibold text-brand-sage hover:underline">{{ __('Add header row') }}</button>
                        @endif
                    </details>
                @endif
                <div>
                    <x-input-label for="new_redirect_comment" :value="__('Comment (optional)')" />
                    <textarea id="new_redirect_comment" wire:model="new_redirect_comment" rows="2" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="{{ __('Why this redirect exists. e.g., Mailchimp broken-URL workaround.') }}"></textarea>
                    <x-input-error :messages="$errors->get('new_redirect_comment')" class="mt-1" />
                </div>
            </form>

            <details class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                        {{ __('Bulk import — paste HTTP redirect rules') }}
                    </span>
                </summary>
                <form wire:submit="bulkImportRedirects" class="mt-3 space-y-3">
                    <div>
                        <x-input-label for="bulk_redirect_input" :value="__('One per line: from,to[,code]')" />
                        <textarea id="bulk_redirect_input" wire:model="bulk_redirect_input" rows="6" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="/old,/new&#10;/legacy,https://example.com,302"></textarea>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Code defaults to 301. Internal rewrites use the single-add form above.') }}</p>
                        <x-input-error :messages="$errors->get('bulk_redirect_input')" class="mt-1" />
                    </div>
                    <div class="flex justify-end">
                        <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="bulkImportRedirects">
                            <span wire:loading.remove wire:target="bulkImportRedirects">{{ __('Import redirects') }}</span>
                            <span wire:loading wire:target="bulkImportRedirects">{{ __('Importing…') }}</span>
                        </x-secondary-button>
                    </div>
                </form>
            </details>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">{{ __('Auto-applied to the webserver after save.') }}</p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="add-redirect-form" wire:loading.attr="disabled" wire:target="addRedirectRule">
                <span wire:loading.remove wire:target="addRedirectRule">{{ __('Add redirect') }}</span>
                <span wire:loading wire:target="addRedirectRule">{{ __('Adding…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    <div class="{{ $card }} mt-6">
        @if ($redirectCount === 0)
            <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss"><x-heroicon-o-arrow-uturn-right class="h-6 w-6" /></span>
                <p class="text-sm font-medium text-brand-ink">{{ __('No redirects yet.') }}</p>
                <p class="text-xs text-brand-moss">{{ __('Add one above or paste a list via Bulk import.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($site->redirects as $redirect)
                    @php
                        $isEditing = $editing_redirect_id === (string) $redirect->id;
                        $isInternal = $redirect->kind === \App\Enums\SiteRedirectKind::InternalRewrite;
                        $headerCount = is_array($redirect->response_headers) ? count($redirect->response_headers) : 0;
                    @endphp
                    <li class="px-6 py-3 sm:px-8" wire:key="redirect-row-{{ $redirect->id }}">
                        @if ($isEditing)
                            <form wire:submit="saveEditedRedirect" class="space-y-3">
                                <div class="grid gap-3 sm:grid-cols-[10rem_1fr_1fr_8rem]">
                                    <div>
                                        <x-input-label :for="'editing_redirect_kind_'.$redirect->id" :value="__('Kind')" />
                                        <select :id="'editing_redirect_kind_'.$redirect->id" wire:model.live="editing_redirect_kind" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                                            <option value="http">{{ __('HTTP redirect') }}</option>
                                            <option value="internal_rewrite">{{ __('Internal rewrite') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <x-input-label :for="'editing_redirect_from_'.$redirect->id" :value="__('From')" />
                                        <x-text-input :id="'editing_redirect_from_'.$redirect->id" wire:model="editing_redirect_from" class="mt-1 block w-full font-mono text-sm" />
                                        <x-input-error :messages="$errors->get('editing_redirect_from')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label :for="'editing_redirect_to_'.$redirect->id" :value="$editing_redirect_kind === 'internal_rewrite' ? __('To path') : __('Destination')" />
                                        <x-text-input :id="'editing_redirect_to_'.$redirect->id" wire:model="editing_redirect_to" class="mt-1 block w-full font-mono text-sm" />
                                        <x-input-error :messages="$errors->get('editing_redirect_to')" class="mt-1" />
                                    </div>
                                    @if ($editing_redirect_kind === 'http')
                                        <div>
                                            <x-input-label :for="'editing_redirect_code_'.$redirect->id" :value="__('Status')" />
                                            <select :id="'editing_redirect_code_'.$redirect->id" wire:model.number="editing_redirect_code" class="mt-1 w-full rounded-md border-slate-300 text-sm">
                                                <option value="301">301</option>
                                                <option value="302">302</option>
                                                <option value="303">303</option>
                                                <option value="307">307</option>
                                                <option value="308">308</option>
                                            </select>
                                        </div>
                                    @endif
                                </div>
                                @if ($editing_redirect_kind === 'http')
                                    <details class="rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                                        <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-brand-mist">
                                            <span class="inline-flex items-center gap-1.5">
                                                <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                                                {{ __('Response headers') }}
                                            </span>
                                        </summary>
                                        @foreach ($editing_redirect_header_rows as $idx => $row)
                                            <div class="mt-3 flex flex-wrap items-end gap-2">
                                                <div class="flex-1 min-w-[10rem]">
                                                    <x-input-label :for="'edit_hdr_name_'.$idx.'_'.$redirect->id" :value="__('Name')" />
                                                    <x-text-input :id="'edit_hdr_name_'.$idx.'_'.$redirect->id" wire:model="editing_redirect_header_rows.{{ $idx }}.name" class="mt-1 w-full font-mono text-xs" />
                                                    <x-input-error :messages="$errors->get('editing_redirect_header_rows.'.$idx.'.name')" class="mt-1" />
                                                </div>
                                                <div class="flex-[2] min-w-[14rem]">
                                                    <x-input-label :for="'edit_hdr_val_'.$idx.'_'.$redirect->id" :value="__('Value')" />
                                                    <x-text-input :id="'edit_hdr_val_'.$idx.'_'.$redirect->id" wire:model="editing_redirect_header_rows.{{ $idx }}.value" class="mt-1 w-full font-mono text-xs" />
                                                    <x-input-error :messages="$errors->get('editing_redirect_header_rows.'.$idx.'.value')" class="mt-1" />
                                                </div>
                                                @if (count($editing_redirect_header_rows) > 1)
                                                    <button type="button" wire:click="removeEditingRedirectHeaderRow({{ $idx }})" class="mb-1 text-xs font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                                                @endif
                                            </div>
                                        @endforeach
                                        @if (count($editing_redirect_header_rows) < 8)
                                            <button type="button" wire:click="addEditingRedirectHeaderRow" class="mt-3 text-xs font-semibold text-brand-sage hover:underline">{{ __('Add header row') }}</button>
                                        @endif
                                    </details>
                                @endif
                                <div>
                                    <x-input-label :for="'editing_redirect_comment_'.$redirect->id" :value="__('Comment (optional)')" />
                                    <textarea :id="'editing_redirect_comment_'.$redirect->id" wire:model="editing_redirect_comment" rows="2" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"></textarea>
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <x-secondary-button type="button" wire:click="cancelEditRedirect">{{ __('Cancel') }}</x-secondary-button>
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditedRedirect">
                                        <span wire:loading.remove wire:target="saveEditedRedirect">{{ __('Save') }}</span>
                                        <span wire:loading wire:target="saveEditedRedirect">{{ __('Saving…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sand/40 text-brand-forest ring-brand-ink/10">
                                        <x-heroicon-o-arrow-uturn-right class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2 break-all font-mono text-sm font-semibold text-brand-ink">
                                            <span>{{ $redirect->from_path }}</span>
                                            <x-heroicon-m-arrow-right class="h-3.5 w-3.5 text-brand-mist" />
                                            <span>{{ $redirect->to_url }}</span>
                                            @if ($isInternal)
                                                <span class="rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Rewrite') }}</span>
                                            @else
                                                <span class="rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ $redirect->status_code }}</span>
                                                @if ($headerCount > 0)
                                                    <span class="text-[10px] text-brand-mist">{{ trans_choice('{1} :count header|[2,*] :count headers', $headerCount, ['count' => $headerCount]) }}</span>
                                                @endif
                                            @endif
                                        </p>
                                        @if ($redirect->comment)
                                            <p class="mt-1 whitespace-pre-line text-[11px] italic text-brand-mist"># {{ $redirect->comment }}</p>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" wire:click="editRedirect('{{ $redirect->id }}')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                        {{ __('Edit') }}
                                    </button>
                                    <button type="button" wire:click="confirmRemoveRedirect({{ $redirect->id }})" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                        <x-heroicon-o-trash class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-cli-snippet class="mt-6" :commands="[
        ['label' => __('Add'), 'command' => 'dply:site:redirect-add '.$site->slug.' /old /new --code=301'],
        ['label' => __('Remove'), 'command' => 'dply:site:redirect-remove '.$site->slug.' /old'],
        ['label' => __('List'), 'command' => 'dply:site:redirect-list '.$site->slug],
        ['label' => __('Bulk import'), 'command' => 'dply:site:redirect-import '.$site->slug.' --file=redirects.csv'],
        ['label' => __('Export CSV'), 'command' => 'dply:site:redirect-export '.$site->slug.' --to=redirects.csv'],
    ]" />

@elseif ($routingTab === 'preview')
    @php $previewCount = $site->previewDomains->count(); @endphp

    <div class="{{ $card }} mt-6">
        <div class="flex flex-col gap-4 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-eye class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Previews') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Preview domains') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Keep preview hostnames separate so reachability, auto-SSL, and cleanup stay scoped to testing traffic.') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ trans_choice('{0} no preview hosts|{1} :count host|[2,*] :count hosts', $previewCount, ['count' => $previewCount]) }}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="{{ $card }} mt-6">
        <form wire:submit="savePreviewSettings" class="space-y-5 px-6 py-6 sm:px-8">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <x-input-label for="preview_primary_hostname" :value="__('Primary preview hostname')" />
                    @if ($this->previewHostnameLocked())
                        <x-text-input id="preview_primary_hostname" :value="$preview_primary_hostname" readonly class="mt-1 block w-full cursor-not-allowed bg-brand-sand/20 font-mono text-sm text-brand-moss" />
                        <p class="mt-1 text-[11px] text-brand-moss">{{ __('Auto-provisioned managed hostname — DNS and the certificate are tied to this exact name, so it can’t be renamed here.') }}</p>
                    @else
                        <x-text-input id="preview_primary_hostname" wire:model="preview_primary_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="preview.example.dply.cc" />
                    @endif
                    <x-input-error :messages="$errors->get('preview_primary_hostname')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="preview_label" :value="__('Label')" />
                    <x-text-input id="preview_label" wire:model="preview_label" class="mt-1 block w-full text-sm" />
                    <x-input-error :messages="$errors->get('preview_label')" class="mt-1" />
                </div>
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 p-4 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="preview_auto_ssl" class="mt-1 rounded border-slate-300" />
                    <span>{{ __('Automatically request SSL once the preview domain is reachable.') }}</span>
                </label>
                <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 p-4 text-sm text-brand-ink">
                    <input type="checkbox" wire:model="preview_https_redirect" class="mt-1 rounded border-slate-300" />
                    <span>{{ __('Redirect preview traffic to HTTPS once a preview certificate is active.') }}</span>
                </label>
            </div>
            <div class="flex justify-end">
                <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="savePreviewSettings">
                    <span wire:loading.remove wire:target="savePreviewSettings">{{ __('Save preview settings') }}</span>
                    <span wire:loading wire:target="savePreviewSettings">{{ __('Saving…') }}</span>
                </x-primary-button>
            </div>
        </form>

        @if ($previewCount > 0)
            <div class="border-t border-brand-ink/10 px-6 py-5 sm:px-8">
                <p class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('Known preview hosts') }}</p>
                <ul class="mt-3 space-y-2">
                    @foreach ($site->previewDomains as $previewDomain)
                        <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 px-4 py-3">
                            <div class="min-w-0">
                                <p class="truncate font-mono text-sm text-brand-ink">{{ $previewDomain->hostname }}</p>
                                <p class="mt-0.5 text-[11px] text-brand-moss">{{ __('DNS: :dns · SSL: :ssl', ['dns' => $previewDomain->dns_status, 'ssl' => $previewDomain->ssl_status]) }}</p>
                            </div>
                            @if (! $previewDomain->is_primary)
                                <button type="button" wire:click="confirmRemovePreviewDomain('{{ $previewDomain->id }}')" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                    <x-heroicon-o-trash class="h-4 w-4" />
                                </button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <x-cli-snippet class="mt-6" :commands="[
        ['label' => __('Set preview'), 'command' => 'dply:site:preview-set '.$site->slug.' preview.example.dply.cc --label=Preview --auto-ssl'],
        ['label' => __('Remove preview'), 'command' => 'dply:site:preview-remove '.$site->slug.' preview.example.dply.cc'],
    ]" />

@elseif ($routingTab === 'tenants')
    @php $tenantCount = $site->tenantDomains->count(); @endphp

    <div class="{{ $card }} mt-6">
        <div class="flex flex-col gap-4 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-7">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-building-office-2 class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Tenants') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Tenant domains') }}</h2>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Multi-tenant hostnames published at the webserver. Your application is responsible for resolving the tenant from the hostname or tenant key.') }}</p>
                    <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-brand-mist">
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-forest"></span>
                            {{ trans_choice('{0} no tenants|{1} :count tenant|[2,*] :count tenants', $tenantCount, ['count' => $tenantCount]) }}
                        </span>
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <button type="button" x-on:click="$dispatch('open-modal', 'add-tenant-modal')" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm shadow-brand-forest/20 transition-colors hover:bg-brand-forest/90">
                    <x-heroicon-o-plus class="h-3.5 w-3.5" />
                    {{ __('Add tenant') }}
                </button>
            </div>
        </div>
    </div>

    <x-modal name="add-tenant-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="relative border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Tenant domain') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Add a tenant domain') }}</h2>
            <p class="mt-2 pr-10 text-sm leading-6 text-brand-moss">{{ __('Hostname + optional tenant key for your app to resolve. Bulk import accepts CSV-style rows.') }}</p>
            <button type="button" x-on:click="$dispatch('close')" class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40" aria-label="{{ __('Close') }}" title="{{ __('Close') }}">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        <div class="px-6 py-6">
            <div class="mb-5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3 text-sm leading-6 text-brand-moss">
                <p class="font-medium text-brand-ink">{{ __('What a tenant domain does') }}</p>
                <p class="mt-1">{{ __('Map many customer hostnames to this one app so a single deployment serves all of them (multi-tenant SaaS). dply adds each hostname to the webserver’s server_name so the site answers for it; your app then decides which tenant a request belongs to — usually from the incoming Host header.') }}</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>{{ __('Hostname — the customer’s domain, pointed at this server by DNS (e.g. app.acme.com).') }}</li>
                    <li>{{ __('Tenant key & Label — your own reference labels. dply stores them but does not inject them into requests, so resolve the tenant from the Host header in your app code.') }}</li>
                    <li>{{ __('After adding a tenant, use “Create testing URL” on its row to get a managed *.on-dply.cc hostname pointed at this app — preview it as that tenant before the customer’s real DNS is live.') }}</li>
                </ul>
                <p class="mt-3 font-medium text-brand-ink">{{ __('Examples') }}</p>
                <ul class="mt-1 space-y-1">
                    <li><code class="font-mono text-xs text-brand-ink">app.acme.com</code> — {{ __('key “acme”, label “Acme Corp”') }}</li>
                    <li><code class="font-mono text-xs text-brand-ink">portal.beta.io</code> — {{ __('key “beta” (label optional)') }}</li>
                </ul>
            </div>
            <form wire:submit="addTenantDomain" id="add-tenant-form" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <x-input-label for="new_tenant_hostname" :value="__('Hostname')" />
                        <x-text-input id="new_tenant_hostname" wire:model="new_tenant_hostname" class="mt-1 block w-full font-mono text-sm" placeholder="customer.example.com" />
                        <x-input-error :messages="$errors->get('new_tenant_hostname')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="new_tenant_key" :value="__('Tenant key (optional)')" />
                        <x-text-input id="new_tenant_key" wire:model="new_tenant_key" class="mt-1 block w-full text-sm" placeholder="acme" />
                        <x-input-error :messages="$errors->get('new_tenant_key')" class="mt-1" />
                    </div>
                </div>
                <div>
                    <x-input-label for="new_tenant_label" :value="__('Label (optional)')" />
                    <x-text-input id="new_tenant_label" wire:model="new_tenant_label" class="mt-1 block w-full text-sm" placeholder="Acme Corp" />
                    <x-input-error :messages="$errors->get('new_tenant_label')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="new_tenant_comment" :value="__('Comment (optional)')" />
                    <textarea id="new_tenant_comment" wire:model="new_tenant_comment" rows="2" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="{{ __('App resolver uses hostname mapping.') }}"></textarea>
                    <x-input-error :messages="$errors->get('new_tenant_comment')" class="mt-1" />
                </div>
            </form>

            <details class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-sand/15 px-4 py-3">
                <summary class="cursor-pointer list-none text-xs font-semibold uppercase tracking-wide text-brand-mist">
                    <span class="inline-flex items-center gap-1.5">
                        <x-heroicon-o-chevron-down class="h-3.5 w-3.5" />
                        {{ __('Bulk import — paste tenants') }}
                    </span>
                </summary>
                <form wire:submit="bulkImportTenantDomains" class="mt-3 space-y-3">
                    <div>
                        <x-input-label for="bulk_tenant_input" :value="__('One per line: hostname,key,label')" />
                        <textarea id="bulk_tenant_input" wire:model="bulk_tenant_input" rows="6" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="acme.example.com,acme,Acme Corp&#10;beta.example.com,beta"></textarea>
                        <p class="mt-1 text-xs text-brand-moss">{{ __('Format: hostname,key,label — key & label optional. e.g. app.acme.com,acme,Acme Corp. Lines starting with # are ignored; hostnames already in use are skipped.') }}</p>
                        <x-input-error :messages="$errors->get('bulk_tenant_input')" class="mt-1" />
                    </div>
                    <div class="flex justify-end">
                        <x-secondary-button type="submit" wire:loading.attr="disabled" wire:target="bulkImportTenantDomains">
                            <span wire:loading.remove wire:target="bulkImportTenantDomains">{{ __('Import tenants') }}</span>
                            <span wire:loading wire:target="bulkImportTenantDomains">{{ __('Importing…') }}</span>
                        </x-secondary-button>
                    </div>
                </form>
            </details>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <p class="mr-auto text-xs text-brand-moss">{{ __('Auto-applied to the webserver after save.') }}</p>
            <x-secondary-button type="button" x-on:click="$dispatch('close')">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="submit" form="add-tenant-form" wire:loading.attr="disabled" wire:target="addTenantDomain">
                <span wire:loading.remove wire:target="addTenantDomain">{{ __('Add tenant') }}</span>
                <span wire:loading wire:target="addTenantDomain">{{ __('Adding…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>

    <div class="{{ $card }} mt-6">
        @if ($tenantCount === 0)
            <div class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center sm:px-8">
                <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/40 text-brand-moss"><x-heroicon-o-building-office-2 class="h-6 w-6" /></span>
                <p class="text-sm font-medium text-brand-ink">{{ __('No tenant domains yet.') }}</p>
                <p class="text-xs text-brand-moss">{{ __('Add one above or paste a list via Bulk import.') }}</p>
            </div>
        @else
            <ul class="divide-y divide-brand-ink/8">
                @foreach ($site->tenantDomains as $tenantDomain)
                    @php $isEditing = $editing_tenant_id === (string) $tenantDomain->id; @endphp
                    <li class="px-6 py-3 sm:px-8" wire:key="tenant-row-{{ $tenantDomain->id }}">
                        @if ($isEditing)
                            <form wire:submit="saveEditedTenantDomain" class="space-y-3">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <x-input-label :for="'editing_tenant_hostname_'.$tenantDomain->id" :value="__('Hostname')" />
                                        <x-text-input :id="'editing_tenant_hostname_'.$tenantDomain->id" wire:model="editing_tenant_hostname" class="mt-1 block w-full font-mono text-sm" />
                                        <x-input-error :messages="$errors->get('editing_tenant_hostname')" class="mt-1" />
                                    </div>
                                    <div>
                                        <x-input-label :for="'editing_tenant_key_'.$tenantDomain->id" :value="__('Tenant key')" />
                                        <x-text-input :id="'editing_tenant_key_'.$tenantDomain->id" wire:model="editing_tenant_key" class="mt-1 block w-full text-sm" />
                                    </div>
                                </div>
                                <div>
                                    <x-input-label :for="'editing_tenant_label_'.$tenantDomain->id" :value="__('Label')" />
                                    <x-text-input :id="'editing_tenant_label_'.$tenantDomain->id" wire:model="editing_tenant_label" class="mt-1 block w-full text-sm" />
                                </div>
                                <div>
                                    <x-input-label :for="'editing_tenant_comment_'.$tenantDomain->id" :value="__('Comment (optional)')" />
                                    <textarea :id="'editing_tenant_comment_'.$tenantDomain->id" wire:model="editing_tenant_comment" rows="2" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"></textarea>
                                </div>
                                <div class="flex items-center justify-end gap-2">
                                    <x-secondary-button type="button" wire:click="cancelEditTenantDomain">{{ __('Cancel') }}</x-secondary-button>
                                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="saveEditedTenantDomain">
                                        <span wire:loading.remove wire:target="saveEditedTenantDomain">{{ __('Save') }}</span>
                                        <span wire:loading wire:target="saveEditedTenantDomain">{{ __('Saving…') }}</span>
                                    </x-primary-button>
                                </div>
                            </form>
                        @else
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex min-w-0 items-start gap-3">
                                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl ring-1 bg-brand-sand/40 text-brand-forest ring-brand-ink/10">
                                        <x-heroicon-o-building-office-2 class="h-4 w-4" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="flex flex-wrap items-center gap-2 truncate font-mono text-sm font-semibold text-brand-ink">
                                            <span>{{ $tenantDomain->hostname }}</span>
                                            @if ($tenantDomain->tenant_key)
                                                <span class="rounded-full bg-brand-sand/40 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('key: :key', ['key' => $tenantDomain->tenant_key]) }}</span>
                                            @endif
                                            @if ($tenantDomain->label)
                                                <span class="text-[11px] font-normal text-brand-mist">· {{ $tenantDomain->label }}</span>
                                            @endif
                                        </p>
                                        @if ($tenantDomain->comment)
                                            <p class="mt-1 whitespace-pre-line text-[11px] italic text-brand-mist"># {{ $tenantDomain->comment }}</p>
                                        @endif
                                        @if ($tenantDomain->testingHostname())
                                            @php $tenantTestStatus = $tenantDomain->testingDnsStatus() ?? 'pending'; @endphp
                                            <p class="mt-1.5 flex flex-wrap items-center gap-2 text-[11px] text-brand-moss">
                                                <x-heroicon-o-beaker class="h-3.5 w-3.5 shrink-0 text-brand-sage" aria-hidden="true" />
                                                <a href="https://{{ $tenantDomain->testingHostname() }}" target="_blank" rel="noopener" class="font-mono text-brand-ink hover:underline">{{ $tenantDomain->testingHostname() }}</a>
                                                <span @class([
                                                    'rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.12em]',
                                                    'bg-emerald-100 text-emerald-900' => $tenantTestStatus === 'ready',
                                                    'bg-rose-100 text-rose-900' => $tenantTestStatus === 'failed',
                                                    'bg-amber-100 text-amber-900' => ! in_array($tenantTestStatus, ['ready', 'failed'], true),
                                                ])>{{ __('testing DNS: :s', ['s' => $tenantTestStatus]) }}</span>
                                            </p>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    @if ($tenantDomain->testingHostname())
                                        <button type="button" wire:click="removeTenantTestingHostname('{{ $tenantDomain->id }}')" wire:loading.attr="disabled" wire:target="removeTenantTestingHostname('{{ $tenantDomain->id }}')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-moss shadow-sm hover:bg-brand-sand/40" title="{{ __('Delete the managed testing hostname for this tenant') }}">
                                            <x-heroicon-o-beaker class="h-3.5 w-3.5" />
                                            {{ __('Remove testing URL') }}
                                        </button>
                                    @else
                                        <button type="button" wire:click="provisionTenantTestingHostname('{{ $tenantDomain->id }}')" wire:loading.attr="disabled" wire:target="provisionTenantTestingHostname('{{ $tenantDomain->id }}')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-forest/30 bg-brand-forest/5 px-2.5 py-1 text-[11px] font-semibold text-brand-forest shadow-sm hover:bg-brand-forest/10" title="{{ __('Provision a dply testing-domain hostname pointed at this app for this tenant') }}">
                                            <x-heroicon-o-beaker class="h-3.5 w-3.5" />
                                            {{ __('Create testing URL') }}
                                        </button>
                                    @endif
                                    <button type="button" wire:click="editTenantDomain('{{ $tenantDomain->id }}')" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                        <x-heroicon-o-pencil-square class="h-3.5 w-3.5" />
                                        {{ __('Edit') }}
                                    </button>
                                    <button type="button" wire:click="confirmRemoveTenantDomain('{{ $tenantDomain->id }}')" class="inline-flex h-8 w-8 items-center justify-center rounded-lg border border-transparent text-brand-mist hover:border-red-200 hover:bg-red-50 hover:text-red-700" title="{{ __('Remove') }}" aria-label="{{ __('Remove') }}">
                                        <x-heroicon-o-trash class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-cli-snippet class="mt-6" :commands="[
        ['label' => __('Add'), 'command' => 'dply:site:tenant-add '.$site->slug.' acme.example.com --key=acme --label=Acme'],
        ['label' => __('Remove'), 'command' => 'dply:site:tenant-remove '.$site->slug.' acme.example.com'],
        ['label' => __('List'), 'command' => 'dply:site:tenant-list '.$site->slug],
    ]" />

@endif

{{-- Primary-hostname rename confirmation modal. Opens when saveEditedDomain()
     detects a non-trivial rename on the primary domain row (existing cert,
     container backend, or auto-derived dns_zone). Lives in the routing partial
     because that's where the edit trigger is (the pencil icon on the primary
     row). Cascade preview is computed by PrimaryHostnameRenamePlanner. --}}
@if ($rename_plan !== null)
    <x-modal name="primary-hostname-rename-modal" maxWidth="2xl" overlayClass="bg-brand-ink/40">
        <div class="border-b border-brand-ink/10 px-6 py-5">
            <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Confirm rename') }}</p>
            <h2 class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Rename primary hostname?') }}</h2>
            <div class="mt-3 inline-flex flex-wrap items-center gap-2 rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-3 py-2 font-mono text-sm text-brand-ink">
                <span class="break-all">{{ $rename_plan['old'] !== '' ? $rename_plan['old'] : __('(none)') }}</span>
                <x-heroicon-o-arrow-right class="h-3.5 w-3.5 shrink-0 text-brand-mist" />
                <span class="break-all">{{ $rename_plan['new'] }}</span>
            </div>
        </div>

        <div class="space-y-5 px-6 py-6">
            {{-- Auto cascades — always-on, read-only checks --}}
            <div>
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Always applied') }}</p>
                <ul class="mt-2 space-y-1.5">
                    @foreach ($rename_plan['auto'] as $row)
                        <li class="flex items-start gap-2 text-sm text-brand-ink">
                            <x-heroicon-m-check-circle class="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" />
                            <span>{{ $row['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Opt-in cascades — operator selects which heavier cleanups to run --}}
            @if (! empty($rename_plan['optIn']))
                <div>
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Opt in') }}</p>
                    <ul class="mt-2 space-y-2">
                        @foreach ($rename_plan['optIn'] as $row)
                            @php
                                $wireModel = match ($row['key']) {
                                    'reissue_cert' => 'rename_reissue_cert',
                                    'cycle_backend' => 'rename_cycle_backend',
                                    default => null,
                                };
                            @endphp
                            @if ($wireModel)
                                <li class="flex items-start gap-2 rounded-xl border border-brand-ink/10 bg-white px-3 py-2.5">
                                    <input id="rename-optin-{{ $row['key'] }}" type="checkbox" wire:model="{{ $wireModel }}" class="mt-0.5 h-4 w-4 rounded border-brand-ink/20 text-brand-forest focus:ring-brand-sage/30" />
                                    <label for="rename-optin-{{ $row['key'] }}" class="text-sm leading-relaxed text-brand-ink">{{ $row['label'] }}</label>
                                </li>
                            @endif
                        @endforeach
                    </ul>
                </div>
            @endif

            {{-- Manual / external — informational; dply cannot fix these from here --}}
            @if (! empty($rename_plan['manual']))
                <div class="rounded-xl border border-amber-200 bg-amber-50/70 px-4 py-3">
                    <p class="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-900">
                        <x-heroicon-m-information-circle class="h-3.5 w-3.5" />
                        {{ __('Cannot be fixed from here') }}
                    </p>
                    <ul class="mt-2 space-y-1 text-sm text-amber-900">
                        @foreach ($rename_plan['manual'] as $line)
                            <li class="flex items-start gap-2">
                                <span class="mt-1.5 inline-block h-1 w-1 shrink-0 rounded-full bg-amber-700"></span>
                                <span>{{ $line }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-brand-ink/10 px-6 py-4">
            <x-secondary-button type="button" wire:click="cancelPrimaryHostnameRename">{{ __('Cancel') }}</x-secondary-button>
            <x-primary-button type="button" wire:click="confirmPrimaryHostnameRename" wire:loading.attr="disabled" wire:target="confirmPrimaryHostnameRename">
                <span wire:loading.remove wire:target="confirmPrimaryHostnameRename">{{ __('Save & apply selected') }}</span>
                <span wire:loading wire:target="confirmPrimaryHostnameRename">{{ __('Saving…') }}</span>
            </x-primary-button>
        </div>
    </x-modal>
@endif
