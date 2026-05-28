@php
    $tonePalette = ['amber' => 'bg-amber-50 text-amber-900 ring-amber-200', 'rose' => 'bg-rose-50 text-rose-700 ring-rose-200', 'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-200'];
    $overallTone = match ($report['overall']) { 'critical' => $tonePalette['rose'], 'warning' => $tonePalette['amber'], default => $tonePalette['emerald'] };
    $sourceLabels = ['profile' => __('Profile'), 'organization' => __('Organization'), 'team' => __('Team'), 'ephemeral' => __('Ephemeral deploy'), 'session' => __('Temporary session'), 'server-local' => __('Server-local')];
@endphp

<x-server-workspace-layout :server="$server" active="ssh-access" :title="__('SSH access')" :description="__('Who has keys on this server — profile, org, team, ephemeral, and server-local entries with sync and review status.')">
    @include('livewire.servers.partials.workspace-scheduled-removal', ['server' => $server])
    <x-explainer><p>{{ __('Read-only graph of authorized_keys managed by Dply. Use SSH keys to add, sync, or preview drift against the server.') }}</p></x-explainer>

    <div class="space-y-6">
        <section class="dply-card overflow-hidden">
            <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-5 sm:px-7">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ring-1 {{ $overallTone }}"><x-heroicon-o-key class="h-5 w-5" /></span>
                        <div>
                            <h2 class="text-base font-semibold text-brand-ink">{{ trans_choice(':count authorized key|:count authorized keys', $report['summary']['total'], ['count' => $report['summary']['total']]) }}</h2>
                            <p class="mt-1 text-sm text-brand-moss">
                                @if ($report['sync']['last_finished_at']){{ __('Last sync :time', ['time' => $report['sync']['last_finished_at']->diffForHumans()]) }}@endif
                                @if ($report['sync']['disabled']) · {{ __('Sync disabled') }}@endif
                            </p>
                        </div>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        @if ($sessionsEnabled)
                            <button type="button" wire:click="openGrantSessionModal" class="inline-flex items-center gap-1 rounded-lg border border-brand-forest/30 bg-brand-forest/5 px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm hover:bg-brand-forest/10">{{ __('Grant session') }}</button>
                        @endif
                        <a href="{{ route('servers.ssh-keys', $server) }}" wire:navigate class="inline-flex items-center gap-1 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Manage keys') }}</a>
                    </div>
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
                <div class="border-b border-brand-ink/10 bg-brand-cream/40 px-6 py-4 sm:px-7">
                    <h2 class="text-sm font-semibold text-brand-ink">{{ trans_choice(':count active contractor session|:count active contractor sessions', $report['summary']['active_sessions'], ['count' => $report['summary']['active_sessions']]) }}</h2>
                    <p class="mt-1 text-xs text-brand-moss">{{ __('Time-boxed keys auto-revoke at expiry. Revoke early from here if access is no longer needed.') }}</p>
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
