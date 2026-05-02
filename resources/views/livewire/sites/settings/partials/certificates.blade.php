<section class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm">
    <form wire:submit="createCertificateRequest">
        <div class="grid gap-0 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <div class="border-b border-brand-ink/10 bg-slate-50/70 p-6 lg:border-b-0 lg:border-r">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Request or install certificates') }}</h2>
                <p class="mt-3 text-sm leading-6 text-brand-moss">
                    {{ __('Create certificates against explicit customer or preview scopes so Dply never guesses which domains should receive SSL.') }}
                </p>
            </div>

            <div class="space-y-5 p-6 sm:p-8">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-input-label for="new_certificate_scope" value="Scope" />
                        <select id="new_certificate_scope" wire:model.live="new_certificate_scope" class="mt-2 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="customer">{{ __('Customer domains') }}</option>
                            <option value="preview">{{ __('Preview domain') }}</option>
                        </select>
                    </div>
                    <div>
                        <x-input-label for="new_certificate_provider_type" value="Certificate type" />
                        <select id="new_certificate_provider_type" wire:model.live="new_certificate_provider_type" class="mt-2 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="letsencrypt">{{ __('Let\'s Encrypt') }}</option>
                            <option value="imported">{{ __('Install existing certificate') }}</option>
                            <option value="csr">{{ __('Create CSR') }}</option>
                            <option value="zerossl">{{ __('ZeroSSL') }}</option>
                        </select>
                    </div>
                </div>

                @if ($new_certificate_scope === 'preview')
                    <div>
                        <x-input-label for="new_certificate_preview_domain_id" value="Preview domain" />
                        <select id="new_certificate_preview_domain_id" wire:model="new_certificate_preview_domain_id" class="mt-2 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">{{ __('Select a preview domain') }}</option>
                            @foreach ($site->previewDomains as $previewDomain)
                                <option value="{{ $previewDomain->id }}">{{ $previewDomain->hostname }}</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('new_certificate_preview_domain_id')" class="mt-2" />
                    </div>
                @else
                    <div>
                        <x-input-label for="new_certificate_domains" value="Domains" />
                        <textarea id="new_certificate_domains" wire:model="new_certificate_domains" rows="3" class="mt-2 block w-full rounded-md border-slate-300 font-mono text-sm shadow-sm" placeholder="app.example.com&#10;www.example.com"></textarea>
                        <x-input-error :messages="$errors->get('new_certificate_domains')" class="mt-2" />
                        <p class="mt-2 text-sm text-brand-moss">{{ __('Leave empty to use the site’s current customer domains.') }}</p>
                    </div>
                @endif

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <x-input-label for="new_certificate_challenge_type" value="Challenge flow" />
                        <select id="new_certificate_challenge_type" wire:model.live="new_certificate_challenge_type" class="mt-2 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="http">{{ __('HTTP challenge') }}</option>
                            <option value="dns">{{ __('DNS provider challenge') }}</option>
                            <option value="imported">{{ __('Imported certificate') }}</option>
                            <option value="manual">{{ __('Manual / CSR') }}</option>
                        </select>
                    </div>
                    @if ($new_certificate_challenge_type === 'dns')
                        <div>
                            <x-input-label for="new_certificate_dns_provider" value="DNS provider" />
                            <select id="new_certificate_dns_provider" wire:model="new_certificate_dns_provider" class="mt-2 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                                <option value="digitalocean">{{ __('DigitalOcean') }}</option>
                            </select>
                        </div>
                    @endif
                </div>

                @if ($new_certificate_challenge_type === 'dns')
                    <div>
                        <x-input-label for="new_certificate_provider_credential_id" value="Provider credential" />
                        <select id="new_certificate_provider_credential_id" wire:model="new_certificate_provider_credential_id" class="mt-2 block w-full rounded-md border-slate-300 text-sm shadow-sm">
                            <option value="">{{ __('Select a credential') }}</option>
                            @foreach ($providerCredentials as $credential)
                                <option value="{{ $credential->id }}">{{ $credential->name }} ({{ ucfirst($credential->provider) }})</option>
                            @endforeach
                        </select>
                        <x-input-error :messages="$errors->get('new_certificate_provider_credential_id')" class="mt-2" />
                    </div>
                @endif

                @if (in_array($new_certificate_provider_type, ['imported', 'csr'], true))
                    <div class="grid gap-4">
                        @if ($new_certificate_provider_type === 'imported')
                            <div>
                                <x-input-label for="new_certificate_certificate_pem" value="Certificate PEM" />
                                <textarea id="new_certificate_certificate_pem" wire:model="new_certificate_certificate_pem" rows="6" class="mt-2 block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm"></textarea>
                            </div>
                            <div>
                                <x-input-label for="new_certificate_private_key_pem" value="Private key PEM" />
                                <textarea id="new_certificate_private_key_pem" wire:model="new_certificate_private_key_pem" rows="6" class="mt-2 block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm"></textarea>
                            </div>
                            <div>
                                <x-input-label for="new_certificate_chain_pem" value="Chain PEM" />
                                <textarea id="new_certificate_chain_pem" wire:model="new_certificate_chain_pem" rows="4" class="mt-2 block w-full rounded-md border-slate-300 font-mono text-xs shadow-sm"></textarea>
                            </div>
                        @endif
                    </div>
                @endif

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 p-4 text-sm text-brand-ink">
                        <input type="checkbox" wire:model="new_certificate_force_skip_dns_checks" class="mt-1 rounded border-slate-300 text-brand-ink shadow-sm" />
                        <span>{{ __('Skip DNS preflight checks when the selected challenge path allows it.') }}</span>
                    </label>
                    @if ($supportsHttp3Certificates)
                        <label class="flex items-start gap-3 rounded-xl border border-brand-ink/10 p-4 text-sm text-brand-ink">
                            <input type="checkbox" wire:model="new_certificate_enable_http3" class="mt-1 rounded border-slate-300 text-brand-ink shadow-sm" />
                            <span>{{ __('Record HTTP/3 intent for this certificate on hosts that support it.') }}</span>
                        </label>
                    @endif
                </div>
            </div>
        </div>

        <div class="flex justify-end border-t border-brand-ink/10 bg-slate-50/40 px-6 py-4 sm:px-8">
            <x-primary-button type="submit">{{ __('Save certificate request') }}</x-primary-button>
        </div>
    </form>
</section>

<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm sm:p-8 space-y-4">
    <div>
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('Existing certificates') }}</h2>
        <p class="mt-1 text-sm text-brand-moss">{{ __('Each certificate keeps its own scope, provider, challenge path, and last output for safer retries and cleanup.') }}</p>
    </div>

    @if ($site->certificates->isEmpty())
        <p class="text-sm text-brand-moss">{{ __('No certificates have been requested for this site yet.') }}</p>
    @else
        <ul class="space-y-3">
            @foreach ($site->certificates as $certificate)
                <li class="rounded-2xl border border-brand-ink/10 px-4 py-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-1">
                            <p class="font-medium text-brand-ink">{{ ucfirst($certificate->provider_type) }} · {{ ucfirst($certificate->scope_type) }}</p>
                            <p class="font-mono text-xs text-brand-moss">{{ implode(', ', $certificate->domainHostnames()) }}</p>
                            <p class="text-xs text-brand-moss">{{ __('Status: :status | Challenge: :challenge', ['status' => $certificate->status, 'challenge' => $certificate->challenge_type]) }}</p>
                            @if ($certificate->last_output)
                                <p class="text-xs text-brand-moss">{{ \Illuminate\Support\Str::limit($certificate->last_output, 180) }}</p>
                            @endif
                        </div>
                        <button type="button" wire:click="removeCertificate('{{ $certificate->id }}')" class="text-sm font-medium text-red-700 hover:underline">{{ __('Remove') }}</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</section>
