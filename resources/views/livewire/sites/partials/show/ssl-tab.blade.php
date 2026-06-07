                    <section class="dply-card overflow-hidden">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <x-icon-badge>
                                <x-heroicon-o-lock-closed class="h-5 w-5" aria-hidden="true" />
                            </x-icon-badge>
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('SSL') }}</p>
                                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Preview & SSL') }}</h3>
                                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Preview hostname reachability and the latest certificate state for this site.') }}</p>
                            </div>
                            <span class="ml-auto inline-flex shrink-0 items-center self-center rounded-full bg-brand-sand/40 px-3 py-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-moss">
                                {{ $site->currentSslSummary() }}
                            </span>
                        </div>

                        <div class="space-y-4 px-6 py-6 sm:px-7">
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
                            \App\Models\SiteCertificate::STATUS_EXPIRED,
                            \App\Models\SiteCertificate::STATUS_PENDING,
                            \App\Models\SiteCertificate::STATUS_ISSUED,
                        ], true))
                            <div class="flex flex-wrap gap-3">
                                @if (in_array($latestCertificate->status, [
                                    \App\Models\SiteCertificate::STATUS_FAILED,
                                    \App\Models\SiteCertificate::STATUS_EXPIRED,
                                ], true))
                                    <button
                                        type="button"
                                        wire:click="repairCertificate('{{ $latestCertificate->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="repairCertificate('{{ $latestCertificate->id }}')"
                                        class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                                    >
                                        <x-heroicon-o-wrench-screwdriver class="h-4 w-4" wire:loading.remove wire:target="repairCertificate('{{ $latestCertificate->id }}')" />
                                        <span wire:loading.remove wire:target="repairCertificate('{{ $latestCertificate->id }}')">{{ __('Repair certificate') }}</span>
                                        <span wire:loading wire:target="repairCertificate('{{ $latestCertificate->id }}')">{{ __('Repairing…') }}</span>
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="retryCertificate('{{ $latestCertificate->id }}')"
                                        wire:loading.attr="disabled"
                                        class="inline-flex items-center justify-center rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90 disabled:opacity-50"
                                    >
                                        <span wire:loading.remove wire:target="retryCertificate('{{ $latestCertificate->id }}')">{{ __('Retry certificate') }}</span>
                                        <span wire:loading wire:target="retryCertificate('{{ $latestCertificate->id }}')">{{ __('Retrying…') }}</span>
                                    </button>
                                @endif
                                <a
                                    href="{{ route('sites.settings', [$server, $site, 'section' => 'certificates']) }}"
                                    wire:navigate
                                    class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
                                >
                                    {{ __('Open certificate settings') }}
                                </a>
                            </div>
                        @endif
                        </div>
                    </section>
