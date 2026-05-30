@php
    $tonePalette = ['amber' => 'bg-amber-50 text-amber-900 ring-amber-200', 'rose' => 'bg-rose-50 text-rose-700 ring-rose-200', 'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'];
    $overallTone = match ($report['overall']) { 'critical' => $tonePalette['rose'], 'warning' => $tonePalette['amber'], default => $tonePalette['emerald'] };
    $sourceLabels = ['profile' => __('Profile'), 'organization' => __('Organization'), 'team' => __('Team'), 'ephemeral' => __('Ephemeral deploy'), 'session' => __('Temporary session'), 'server-local' => __('Server-local'), 'historical' => __('Historical'), 'platform' => __('Dply platform')];
    $timelineRangeLabels = ['7d' => __('7 days'), '30d' => __('30 days'), '90d' => __('90 days')];
@endphp

<x-server-workspace-layout :server="$server" active="ssh-access" :title="__('Access graph')" :description="__('Who had SSH access on this server over time — your keys, temporary sessions, and when Dply accessed the server to run jobs.')">
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])
    <x-explainer><p>{{ __('Tracks authorized_keys managed by Dply over time, temporary session grants, and platform SSH sessions when Dply runs installs, deploys, or workspace actions on this server.') }}</p></x-explainer>

    <div class="space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-chart-bar class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Access over time') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Active SSH access') }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        {{ __(':from — :to', ['from' => $timeline['from']->format('M j'), 'to' => $timeline['to']->format('M j, Y')]) }}
                        @if ($timeline['you_active_now'])
                            · <span class="font-medium text-amber-800">{{ __('You have access now') }}</span>
                        @endif
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-1.5">
                    @foreach ($timelineRangeLabels as $value => $label)
                        <button
                            type="button"
                            wire:click="$set('timeline_range', '{{ $value }}')"
                            @class([
                                'rounded-full px-3 py-1 text-xs font-semibold ring-1 transition',
                                'bg-brand-forest text-white ring-brand-forest' => $timeline_range === $value,
                                'border border-brand-ink/15 bg-white text-brand-moss hover:bg-brand-sand/40 ring-transparent' => $timeline_range !== $value,
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="space-y-5 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-center gap-4 text-[11px] text-brand-moss">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-0.5 w-5 rounded bg-brand-forest"></span>
                        {{ __('Total active keys') }}
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-0.5 w-5 rounded bg-amber-600"></span>
                        {{ __('Your access') }}
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-0.5 w-5 rounded bg-sky-600"></span>
                        {{ __('Dply platform') }}
                    </span>
                </div>

                <x-access-graph-chart :series="$timeline['series']" wire:key="access-graph-{{ $timeline_range }}" />

                @if (count($timeline['lanes']) > 0)
                    <div class="space-y-2">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Access lanes') }}</p>
                        <div class="space-y-1.5">
                            @foreach ($timeline['lanes'] as $lane)
                                <div wire:key="access-lane-{{ $lane['key'] }}" class="grid grid-cols-[minmax(0,9rem)_1fr] items-center gap-3 text-xs">
                                    <div class="min-w-0 truncate">
                                        <span @class(['font-semibold', 'text-amber-800' => $lane['is_you'], 'text-sky-800' => ! $lane['is_you'] && ($lane['source'] ?? '') === 'platform', 'text-brand-ink' => ! $lane['is_you'] && ($lane['source'] ?? '') !== 'platform'])>
                                            @if ($lane['is_you'])
                                                {{ __('You') }}
                                            @elseif (($lane['source'] ?? '') === 'platform')
                                                {{ __('Dply') }}
                                            @else
                                                {{ $lane['label'] }}
                                            @endif
                                        </span>
                                        @if ($lane['is_you'])
                                            <span class="ml-1 text-[10px] text-brand-mist">({{ $lane['label'] }})</span>
                                        @elseif (($lane['source'] ?? '') === 'platform')
                                            <span class="ml-1 text-[10px] text-brand-mist">({{ $lane['label'] }})</span>
                                        @endif
                                    </div>
                                    <div class="relative h-5 rounded-md bg-brand-sand/30 ring-1 ring-brand-ink/5">
                                        <div
                                            @class([
                                                'absolute inset-y-0 rounded-md',
                                                'bg-amber-400/70 ring-1 ring-amber-500/40' => $lane['is_you'],
                                                'bg-sky-400/60 ring-1 ring-sky-500/35' => ! $lane['is_you'] && ($lane['source'] ?? '') === 'platform',
                                                'bg-brand-forest/35 ring-1 ring-brand-forest/20' => ! $lane['is_you'] && ($lane['source'] ?? '') !== 'platform',
                                            ])
                                            style="left: {{ $lane['left_pct'] }}%; width: {{ $lane['width_pct'] }}%;"
                                            title="{{ $lane['start']->format('M j, H:i') }} — {{ $lane['end']->format('M j, H:i') }}"
                                        ></div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (count($timeline['events']) > 0)
                    <div class="border-t border-brand-ink/8 pt-4">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Recent changes') }}</p>
                        <ul class="mt-3 space-y-2">
                            @foreach ($timeline['events'] as $event)
                                <li wire:key="access-event-{{ $event['at']->timestamp }}-{{ $event['label'] }}" class="flex flex-wrap items-start justify-between gap-2 rounded-lg border border-brand-ink/8 bg-white px-3 py-2 text-sm">
                                    <div>
                                        <p @class(['font-semibold', 'text-amber-800' => $event['is_you'], 'text-sky-800' => ! $event['is_you'] && ($event['source'] ?? '') === 'platform', 'text-brand-ink' => ! $event['is_you'] && ($event['source'] ?? '') !== 'platform'])>{{ $event['label'] }}</p>
                                        <p class="text-[11px] text-brand-moss">{{ $event['detail'] }}</p>
                                    </div>
                                    <span class="text-[11px] text-brand-mist" title="{{ $event['at']->toIso8601String() }}">{{ $event['at']->diffForHumans() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>
        </section>
        <section class="dply-card overflow-hidden">
            <div class="flex flex-wrap items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25"><x-heroicon-o-key class="h-5 w-5" /></span>
                <div class="min-w-0">
                    <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Access') }}</p>
                    <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ trans_choice(':count authorized key|:count authorized keys', $report['summary']['total'], ['count' => $report['summary']['total']]) }}</h2>
                    <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                        @if ($report['sync']['last_finished_at']){{ __('Last sync :time', ['time' => $report['sync']['last_finished_at']->diffForHumans()]) }}@endif
                        @if ($report['sync']['disabled']) · {{ __('Sync disabled') }}@endif
                        @if (($report['summary']['platform_access_recent'] ?? 0) > 0)
                            · {{ trans_choice(':count Dply platform access in the last 30 days|:count Dply platform accesses in the last 30 days', $report['summary']['platform_access_recent'], ['count' => $report['summary']['platform_access_recent']]) }}
                        @endif
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2 sm:ml-auto">
                    @if ($sessionsEnabled)
                        <button type="button" wire:click="openGrantSessionModal" class="inline-flex items-center gap-1 rounded-lg border border-brand-forest/30 bg-brand-forest/5 px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm hover:bg-brand-forest/10">{{ __('Grant session') }}</button>
                    @endif
                    <a href="{{ route('servers.ssh-keys', $server) }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Manage keys') }}</a>
                </div>
            </div>
            @if ($report['alert_count'] > 0)
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($report['alerts'] as $alert)
                        <li class="flex flex-wrap justify-between gap-3 px-6 py-4 sm:px-7">
                            <div><p class="text-sm font-semibold text-brand-ink">{{ $alert['title'] }}</p><p class="text-sm text-brand-moss">{{ $alert['message'] }}</p></div>
                            @if ($alert['href'])<a href="{{ $alert['href'] }}" wire:navigate class="text-xs font-semibold text-brand-forest hover:underline">{{ $alert['link_label'] }}</a>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if (count($report['rows']) > 0)
            <section class="dply-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-left text-xs">
                        <thead class="bg-brand-sand/30 text-brand-moss"><tr><th class="px-3 py-2">{{ __('Name') }}</th><th class="px-3 py-2">{{ __('Source') }}</th><th class="px-3 py-2">{{ __('User') }}</th><th class="px-3 py-2">{{ __('Synced') }}</th><th class="px-3 py-2">{{ __('Review') }}</th></tr></thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($report['rows'] as $row)
                                <tr @class(['bg-amber-50/40' => $row['review_overdue']])>
                                    <td class="px-3 py-2 font-medium text-brand-ink">{{ $row['name'] }}</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ $sourceLabels[$row['source']] ?? $row['source'] }}</td>
                                    <td class="px-3 py-2 font-mono text-brand-moss">{{ $row['target_linux_user'] }}</td>
                                    <td class="px-3 py-2 text-brand-moss">{{ $row['synced_at']?->diffForHumans() ?? '—' }}</td>
                                    <td class="px-3 py-2 text-brand-moss">@if ($row['review_after']){{ $row['review_after']->format('Y-m-d') }}@else—@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if ($sessionsEnabled && count($report['sessions']) > 0)
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Sessions') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ trans_choice(':count active contractor session|:count active contractor sessions', $report['summary']['active_sessions'], ['count' => $report['summary']['active_sessions']]) }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Time-boxed keys auto-revoke at expiry. Revoke early from here if access is no longer needed.') }}</p>
                    </div>
                </div>
                <ul class="divide-y divide-brand-ink/10">
                    @foreach ($report['sessions'] as $session)
                        <li class="flex flex-wrap items-center justify-between gap-3 px-6 py-4 sm:px-7">
                            <div>
                                <p class="text-sm font-semibold text-brand-ink">{{ $session['name'] }}</p>
                                <p class="text-xs text-brand-moss">{{ __('Expires :time · :user', ['time' => $session['expires_at']->diffForHumans(), 'user' => $session['created_by'] ?: __('Unknown')]) }}</p>
                            </div>
                            <button type="button" wire:click="openRevokeSessionModal('{{ $session['id'] }}')" class="text-xs font-semibold text-rose-700 hover:underline">{{ __('Revoke') }}</button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    @if ($sessionsEnabled)
        <x-modal name="grant-ssh-session" maxWidth="2xl" overlayClass="bg-brand-ink/40">
            <div class="relative border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3 pr-10">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-forest/10 text-brand-forest ring-1 ring-brand-forest/20">
                        <x-heroicon-o-clock class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Temporary access') }}</p>
                        <h2 class="mt-0.5 text-xl font-semibold text-brand-ink">{{ __('Grant temporary SSH session') }}</h2>
                        <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                            {{ __('Paste a contractor public key. Dply installs it on the server and removes it automatically when the session expires.') }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    x-on:click="$dispatch('close-modal', 'grant-ssh-session')"
                    class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                    aria-label="{{ __('Close') }}"
                >
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <form wire:submit="grantSession">
                <div class="space-y-5 px-6 py-6 sm:px-7">
                    <div>
                        <x-input-label for="session_name" :value="__('Session label')" />
                        <x-text-input
                            wire:model="session_name"
                            id="session_name"
                            class="mt-1.5 block w-full"
                            placeholder="{{ __('Contractor — sprint review') }}"
                            autocomplete="off"
                        />
                        <p class="mt-1.5 text-xs text-brand-moss">{{ __('Shown in the access graph and audit log.') }}</p>
                        <x-input-error :messages="$errors->get('session_name')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="session_public_key" :value="__('Public key')" />
                        <textarea
                            wire:model="session_public_key"
                            id="session_public_key"
                            rows="4"
                            spellcheck="false"
                            autocomplete="off"
                            placeholder="ssh-ed25519 AAAA… contractor@laptop"
                            class="mt-1.5 block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2.5 font-mono text-xs leading-relaxed text-brand-ink shadow-sm focus:border-brand-forest focus:ring-brand-forest/30"
                        ></textarea>
                        <p class="mt-1.5 text-xs text-brand-moss">{{ __('OpenSSH format — one line starting with ssh-rsa, ssh-ed25519, or ecdsa.') }}</p>
                        <x-input-error :messages="$errors->get('session_public_key')" class="mt-1" />
                    </div>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="space-y-3">
                            <div>
                                <x-input-label for="session_duration_hours" :value="__('Duration (hours)')" />
                                <x-text-input
                                    wire:model="session_duration_hours"
                                    id="session_duration_hours"
                                    type="number"
                                    min="1"
                                    class="mt-1.5 block w-full tabular-nums"
                                />
                                <x-input-error :messages="$errors->get('session_duration_hours')" class="mt-1" />
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Quick pick') }}</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    @foreach ($durationPresets as $hours)
                                        @php $active = (int) $session_duration_hours === (int) $hours; @endphp
                                        <button
                                            type="button"
                                            wire:click="$set('session_duration_hours', {{ (int) $hours }})"
                                            @class([
                                                'rounded-full px-3 py-1 text-xs font-semibold ring-1 transition',
                                                'bg-brand-forest text-white ring-brand-forest' => $active,
                                                'border border-brand-ink/15 bg-white text-brand-moss hover:bg-brand-sand/40 ring-transparent' => ! $active,
                                            ])
                                        >
                                            {{ trans(':hours h', ['hours' => $hours]) }}
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        </div>

                        <div>
                            <x-input-label for="session_linux_user" :value="__('Linux user')" />
                            <x-text-input
                                wire:model="session_linux_user"
                                id="session_linux_user"
                                class="mt-1.5 block w-full font-mono text-sm"
                                autocomplete="off"
                            />
                            <p class="mt-1.5 text-xs text-brand-moss">{{ __('Target account on the server — usually the deploy user.') }}</p>
                            <x-input-error :messages="$errors->get('session_linux_user')" class="mt-1" />
                        </div>
                    </div>
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 bg-brand-sand/10 px-6 py-4 sm:flex-row sm:items-center sm:justify-end sm:gap-3 sm:px-7">
                    <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'grant-ssh-session')">
                        {{ __('Cancel') }}
                    </x-secondary-button>
                    <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="grantSession">
                        <span wire:loading.remove wire:target="grantSession">{{ __('Grant session') }}</span>
                        <span wire:loading wire:target="grantSession">{{ __('Granting…') }}</span>
                    </x-primary-button>
                </div>
            </form>
        </x-modal>

        <x-modal name="revoke-ssh-session" maxWidth="md" overlayClass="bg-brand-ink/40">
            <div class="relative border-b border-brand-ink/10 bg-rose-50/40 px-6 py-5 sm:px-7">
                <div class="flex items-start gap-3 pr-8">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-rose-100 text-rose-700 ring-1 ring-rose-200">
                        <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div>
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-rose-800">{{ __('Revoke access') }}</p>
                        <h2 class="mt-0.5 text-lg font-semibold text-brand-ink">{{ __('Revoke SSH session?') }}</h2>
                        <p class="mt-2 text-sm leading-relaxed text-brand-moss">
                            {{ __('This removes the session key from authorized_keys on the server immediately. The contractor will lose SSH access.') }}
                        </p>
                    </div>
                </div>
                <button
                    type="button"
                    x-on:click="$dispatch('close-modal', 'revoke-ssh-session')"
                    class="absolute right-4 top-4 inline-flex h-8 w-8 items-center justify-center rounded-lg text-brand-mist transition-colors hover:bg-brand-sand/40 hover:text-brand-ink focus:outline-none focus:ring-2 focus:ring-brand-sage/40"
                    aria-label="{{ __('Close') }}"
                >
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3 sm:px-7">
                <x-secondary-button type="button" x-on:click="$dispatch('close-modal', 'revoke-ssh-session')">
                    {{ __('Cancel') }}
                </x-secondary-button>
                <x-danger-button type="button" wire:click="revokeSession" wire:loading.attr="disabled" wire:target="revokeSession">
                    <span wire:loading.remove wire:target="revokeSession">{{ __('Revoke session') }}</span>
                    <span wire:loading wire:target="revokeSession">{{ __('Revoking…') }}</span>
                </x-danger-button>
            </div>
        </x-modal>
    @endif
</x-server-workspace-layout>
