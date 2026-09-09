        {{-- PHP-FPM --}}
        @if (! empty($phpFpm['versions']))
            <div class="{{ $card }}">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-cpu-chip"
                    :title="__('PHP-FPM')"
                    :count="count($phpFpm['versions']) > 0 ? trans_choice('{1} :count version|[2,*] :count versions', count($phpFpm['versions']), ['count' => count($phpFpm['versions'])]) : null"
                    :note="__('Detected installations under /etc/php/. Default is set in server meta and used by deploys and the per-row PHP-FPM actions.')"
                    class="border-b border-brand-ink/10"
                />

                <div class="mx-4 my-3.5 overflow-hidden rounded-xl border border-brand-ink/10 sm:mx-5">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/30 text-left text-xs uppercase tracking-wide text-brand-mist">
                            <tr>
                                <th class="px-3 py-1.5 font-semibold">{{ __('Version') }}</th>
                                <th class="px-3 py-1.5 font-semibold">{{ __('Status') }}</th>
                                <th class="px-3 py-1.5 font-semibold">{{ __('Default') }}</th>
                                <th class="px-3 py-1.5 font-semibold">{{ __('Pools') }}</th>
                                @if ($opsReady && ! $isDeployer)
                                    <th class="px-3 py-1.5 font-semibold text-right">{{ __('Actions') }}</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($phpFpm['versions'] as $row)
                                @php $rowPill = $statePill($row['active'] ?? null); @endphp
                                <tr>
                                    <td class="px-3 py-1.5 font-mono text-xs text-brand-ink">{{ $row['version'] }}</td>
                                    <td class="px-3 py-1.5">
                                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium {{ $rowPill['classes'] }}">
                                            <span aria-hidden="true" class="inline-block h-1.5 w-1.5 rounded-full {{ $rowPill['dot'] }}"></span>
                                            {{ $rowPill['label'] }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-1.5 text-xs">
                                        @if ($row['version'] === $defaultPhp)
                                            <span class="font-medium text-brand-forest">★ {{ __('Default') }}</span>
                                        @else
                                            <span class="text-brand-mist">—</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-1.5 font-mono text-xs text-brand-moss">{{ $row['pools_count'] }}</td>
                                    @if ($opsReady && ! $isDeployer)
                                        <td class="px-3 py-1.5 text-right">
                                            <div class="inline-flex flex-wrap justify-end gap-1.5">
                                                @if (! empty($serviceActions['restart_php_fpm']))
                                                    @php $a = $serviceActions['restart_php_fpm']; @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('runAllowlistedAction', ['restart_php_fpm'], @js($a['label']), @js($a['confirm']), @js($a['label']), false)"
                                                        class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                                    >{{ __('Restart') }}</button>
                                                @endif
                                                @if (! empty($serviceActions['reload_php_fpm']))
                                                    @php $a = $serviceActions['reload_php_fpm']; @endphp
                                                    <button
                                                        type="button"
                                                        wire:click="openConfirmActionModal('runAllowlistedAction', ['reload_php_fpm'], @js($a['label']), @js($a['confirm']), @js($a['label']), false)"
                                                        class="rounded-md border border-brand-ink/15 bg-white px-2 py-0.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                                    >{{ __('Reload') }}</button>
                                                @endif
                                            </div>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="px-4 pb-3 text-xs text-brand-mist sm:px-5">{{ __('Restart and Reload act on the default PHP version (set in server meta). Per-version targeting is on the roadmap.') }}</p>
            </div>
        @endif

        {{-- TLS / certbot --}}
        @if (! empty($certbot['present']))
            <div class="{{ $card }}">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-lock-closed"
                    :title="__('TLS / certbot')"
                    :count="! empty($certs) ? trans_choice('{1} :count certificate|[2,*] :count certificates', count($certs), ['count' => count($certs)]) : null"
                    :note="__('Certificates managed by certbot on this server. Dry-run before renewing if you’re unsure.')"
                    class="border-b border-brand-ink/10"
                >
                    @if ($opsReady && ! $isDeployer)
                        <x-slot:actions>
                            @foreach (['certbot_renew_dry_run', 'certbot_renew_all'] as $cbKey)
                                @if (! empty($serviceActions[$cbKey]))
                                    @php $a = $serviceActions[$cbKey]; @endphp
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('runAllowlistedAction', ['{{ $cbKey }}'], @js($a['label']), @js($a['confirm']), @js($a['label']), {{ $cbKey === 'certbot_renew_all' ? 'true' : 'false' }})"
                                        class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
                                    >{{ $a['label'] }}</button>
                                @endif
                            @endforeach
                        </x-slot:actions>
                    @endif
                </x-workspace-panel-head>

                @if (! empty($certs))
                    <div class="mx-4 my-3.5 overflow-hidden rounded-xl border border-brand-ink/10 sm:mx-5">
                        <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                            <thead class="bg-brand-sand/30 text-left text-xs uppercase tracking-wide text-brand-mist">
                                <tr>
                                    <th class="px-3 py-1.5 font-semibold">{{ __('Domains') }}</th>
                                    <th class="px-3 py-1.5 font-semibold">{{ __('Expires') }}</th>
                                    <th class="px-3 py-1.5 font-semibold">{{ __('Days remaining') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-ink/5 bg-white">
                                @foreach ($certs as $cert)
                                    @php
                                        $days = $cert['valid'];
                                        $tone = $days === null
                                            ? 'text-brand-moss'
                                            : ($days < 0 ? 'text-red-700 font-semibold' : ($days < 14 ? 'text-red-700 font-semibold' : ($days < 30 ? 'text-amber-700 font-medium' : 'text-brand-ink')));
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-1.5 text-xs">
                                            <div class="font-medium text-brand-ink">{{ $cert['name'] }}</div>
                                            @if ($cert['domains'])
                                                <div class="font-mono text-xs text-brand-moss">{{ $cert['domains'] }}</div>
                                            @endif
                                        </td>
                                        <td class="px-3 py-1.5 font-mono text-xs text-brand-moss">{{ $cert['expiry'] ?: '—' }}</td>
                                        <td class="px-3 py-1.5 text-xs {{ $tone }}">
                                            @if ($days === null) — @elseif ($days < 0) {{ __('Invalid') }} @else {{ trans_choice(':n day|:n days', $days, ['n' => $days]) }} @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="px-4 py-3.5 text-xs text-brand-moss sm:px-5">{{ __('certbot is installed but no certificates are managed yet.') }}</p>
                @endif
            </div>
        @endif

        {{-- Switch history --}}
        @if ($recentSwitches->isNotEmpty())
            <div class="{{ $card }}">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-clock"
                    :title="__('Switch history')"
                    :count="(string) $recentSwitches->count()"
                    :note="__('Recent webserver switches on this server.')"
                    class="border-b border-brand-ink/10"
                />

                <ul class="mx-4 my-3.5 divide-y divide-brand-ink/8 overflow-hidden rounded-xl border border-brand-ink/10 sm:mx-5">
                    @foreach ($recentSwitches as $event)
                        @php
                            $payload = is_array($event->payload) ? $event->payload : [];
                            $isSuccess = $event->result_status === \App\Models\ServerWebserverAuditEvent::RESULT_SUCCESS;
                            $isRollback = $event->action === \App\Models\ServerWebserverAuditEvent::ACTION_ROLLBACK;
                            $statusClasses = $isSuccess
                                ? 'bg-brand-sage/15 text-brand-forest ring-brand-sage/30'
                                : 'bg-rose-50 text-rose-800 ring-rose-200';
                            $sitesCount = (int) ($payload['sites_affected'] ?? 0);
                            $durationMs = (int) ($payload['duration_ms'] ?? 0);
                        @endphp
                        <li class="bg-white px-4 py-2.5">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="inline-flex items-center gap-2 rounded-lg border border-brand-ink/10 bg-brand-sand/30 px-2 py-0.5 font-mono text-xs text-brand-ink">
                                        {{ $payload['from'] ?? '—' }}
                                        <x-heroicon-o-arrow-right class="h-3 w-3 text-brand-mist" />
                                        {{ $payload['to'] ?? '—' }}
                                    </span>
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide ring-1 {{ $statusClasses }}">
                                        @if ($isRollback)
                                            {{ __('rolled back') }}
                                        @elseif ($isSuccess)
                                            {{ __('success') }}
                                        @else
                                            {{ __('failed') }}
                                        @endif
                                    </span>
                                    @if ($sitesCount > 0)
                                        <span class="inline-flex items-center rounded-full bg-brand-ink/10 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">
                                            {{ trans_choice(':n site|:n sites', $sitesCount, ['n' => $sitesCount]) }}
                                        </span>
                                    @endif
                                    @if (! empty($payload['tls_opt_in']))
                                        <span class="inline-flex items-center rounded-full bg-brand-sage/15 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('TLS handover') }}</span>
                                    @endif
                                </div>
                                <div class="text-right text-xs text-brand-moss">
                                    <p>{{ optional($event->user)->name ?? __('system') }}</p>
                                    <p class="text-brand-mist">{{ $event->created_at?->diffForHumans() }}</p>
                                </div>
                            </div>
                            @if (! $isSuccess && ! empty($payload['reason']))
                                <p class="mt-2 break-words font-mono text-xs text-rose-800">{{ $payload['reason'] }}</p>
                            @endif
                            @if ($durationMs > 0)
                                <p class="mt-1 text-2xs text-brand-mist">
                                    {{ __('Duration:') }} <span class="font-mono">{{ $durationMs < 1000 ? $durationMs.' ms' : round($durationMs / 1000, 1).' s' }}</span>
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if (empty($phpFpm['versions']) && empty($certbot['present']) && $recentSwitches->isEmpty())
            <div class="m-4 rounded-xl border border-dashed border-brand-ink/15 bg-white px-4 py-6 text-center text-xs text-brand-moss sm:m-5">
                <p>{{ __('Nothing to show here yet. PHP-FPM versions, certbot certificates, and switch history will appear once the server has any to report.') }}</p>
            </div>
        @endif
