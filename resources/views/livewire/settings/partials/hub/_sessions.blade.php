{{-- Profile hub: the sessions block. Extracted so the page body can be
     laid out differently without four copies of the controls. --}}
            {{-- Active sessions --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-device-phone-mobile"
                    :title="__('Active sessions')"
                    :count="count($sessions) ?: null"
                    :note="__('Revoking a session logs that device out on its next request.')"
                >
                    <x-slot:actions>
                        <p x-show="sessionRevoked" x-transition x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Session revoked.') }}
                        </p>
                        <p x-show="sessionsRevoked" x-transition x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-emerald-700">
                            <x-heroicon-m-check-circle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('All other sessions revoked.') }}
                        </p>
                        @if ($otherSessions > 0)
                            <button type="button" wire:click="openConfirmActionModal('revokeOtherSessions', [], @js(__('Revoke all other sessions')), @js(__('Revoke all other sessions? You will stay logged in on this device only.')), @js(__('Revoke sessions')), true)" class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-red-200 bg-red-50 px-2 text-xs font-semibold text-red-700 shadow-sm transition hover:bg-red-100">
                                <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Revoke other devices') }}
                            </button>
                        @endif
                    </x-slot:actions>
                </x-workspace-panel-head>
                @error('session')
                    <p class="px-3 pt-2 text-xs text-red-600 sm:px-4">{{ $message }}</p>
                @enderror

                @if ($sessions === [])
                    <div class="px-3 py-3 text-center sm:px-4">
                        <p class="text-xs text-brand-mist">{{ __('No active sessions.') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($sessions as $session)
                            <li class="flex items-center justify-between gap-3 px-3 py-2 transition-colors hover:bg-brand-sand/15 sm:px-4">
                                <div class="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                    <span class="truncate text-sm font-semibold text-brand-ink">{{ $session['device_label'] }}</span>
                                    @if ($session['is_current'])
                                        <span class="inline-flex items-center rounded border border-brand-sage/30 bg-brand-sage/15 px-1 py-px text-2xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('This device') }}</span>
                                    @endif
                                    <span class="truncate text-xs text-brand-moss">
                                        <span class="font-mono">{{ $session['ip_address'] ?? __('Unknown IP') }}</span>
                                        <span class="text-brand-mist"> · </span>
                                        {{ __('Last active :time', ['time' => \Carbon\Carbon::createFromTimestamp($session['last_activity'])->diffForHumans()]) }}
                                    </span>
                                </div>
                                @if (! $session['is_current'])
                                    <button
                                        type="button"
                                        wire:click="openConfirmActionModal('revokeSession', ['{{ $session['id'] }}'], @js(__('Revoke session')), @js(__('Revoke this session? That device will be logged out on its next request.')), @js(__('Revoke')), true)"
                                        class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-rose-200 bg-white px-2 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                    >
                                        <x-heroicon-o-x-mark class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ __('Revoke') }}
                                    </button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

