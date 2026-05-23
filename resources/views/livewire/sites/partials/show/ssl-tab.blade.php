                    <section class="dply-card overflow-hidden p-6 sm:p-8 space-y-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <h3 class="text-base font-semibold text-brand-ink">{{ __('Preview & SSL') }}</h3>
                                <p class="mt-1 text-sm text-brand-moss">{{ __('Preview hostname reachability and the latest certificate state for this site.') }}</p>
                            </div>
                            <span class="inline-flex items-center rounded-full bg-brand-sand/40 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                                {{ $site->currentSslSummary() }}
                            </span>
                        </div>

                        <dl class="grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Preview hostname') }}</dt>
                                <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ $previewDomain?->hostname ?? __('No preview domain') }}</dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Preview DNS') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">{{ $previewDomain?->dns_status ?? __('Not configured') }}</dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Latest certificate') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">
                                    @if ($latestCertificate)
                                        {{ ucfirst($latestCertificate->provider_type) }} · {{ $latestCertificate->status }}
                                    @else
                                        {{ __('No certificates requested') }}
                                    @endif
                                </dd>
                            </div>
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4">
                                <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Certificate scope') }}</dt>
                                <dd class="mt-2 text-sm text-brand-ink">{{ $latestCertificate ? ucfirst($latestCertificate->scope_type) : __('—') }}</dd>
                            </div>
                            @if ($latestCertificate)
                                <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                    <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Certificate domains') }}</dt>
                                    <dd class="mt-2 break-all font-mono text-sm text-brand-ink">{{ implode(', ', $latestCertificate->domainHostnames()) }}</dd>
                                </div>
                                @if (! empty($latestCertificate->last_output))
                                    <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/15 p-4 sm:col-span-2">
                                        <dt class="text-[11px] font-semibold uppercase tracking-[0.18em] text-brand-mist">{{ __('Latest certificate output') }}</dt>
                                        <dd class="mt-2 whitespace-pre-wrap break-words font-mono text-xs text-brand-ink">{{ \Illuminate\Support\Str::limit($latestCertificate->last_output, 800) }}</dd>
                                    </div>
                                @endif
                            @endif
                        </dl>

                        @if ($latestCertificate && in_array($latestCertificate->status, [
                            \App\Models\SiteCertificate::STATUS_FAILED,
                            \App\Models\SiteCertificate::STATUS_PENDING,
                            \App\Models\SiteCertificate::STATUS_ISSUED,
                        ], true))
                            <div class="flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    wire:click="retryCertificate('{{ $latestCertificate->id }}')"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center justify-center rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                                >
                                    <span wire:loading.remove wire:target="retryCertificate('{{ $latestCertificate->id }}')">{{ __('Retry certificate') }}</span>
                                    <span wire:loading wire:target="retryCertificate('{{ $latestCertificate->id }}')">{{ __('Retrying…') }}</span>
                                </button>
                                <a
                                    href="{{ route('sites.settings', [$server, $site, 'section' => 'certificates']) }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    {{ __('Open certificate settings') }}
                                </a>
                            </div>
                        @endif
                    </section>
