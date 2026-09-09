{{-- Security: the two factor block. Extracted so the page layout
     can change without touching the controls. --}}
        {{-- Two-factor authentication --}}
        <div>
            <x-workspace-panel-head
                dense
                icon="heroicon-o-device-phone-mobile"
                :title="__('Two-factor authentication')"
                :note="__('Require a code from your authenticator app when signing in.')"
                :tone="$twoFactorOn ? null : 'amber'"
            >
                <x-slot:actions>
                    <span @class([
                        'inline-flex items-center gap-1 rounded-full px-1.5 py-px text-2xs font-semibold ring-1',
                        'bg-brand-sage/15 text-brand-forest ring-brand-sage/20' => $twoFactorOn,
                        'bg-amber-50 text-amber-900 ring-amber-200' => ! $twoFactorOn,
                    ])>
                        <span @class([
                            'inline-block h-1.5 w-1.5 rounded-full',
                            'bg-brand-sage' => $twoFactorOn,
                            'bg-amber-500' => ! $twoFactorOn,
                        ])></span>
                        {{ $twoFactorOn ? __('Enabled') : __('Disabled') }}
                    </span>
                    @if ($twoFactorOn)
                        <a href="{{ route('two-factor.setup') }}" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-o-cog-6-tooth class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Manage or disable') }}
                        </a>
                    @else
                        <a href="{{ route('two-factor.setup') }}" class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest">
                            <x-heroicon-o-shield-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Set up 2FA') }}
                        </a>
                    @endif
                </x-slot:actions>
            </x-workspace-panel-head>
            @if (session('status') === 'two-factor-enabled' && session('recovery_codes'))
                {{-- Recovery codes are the only thing worth a body row here: the
                     enable/disable action now rides in the header strip. --}}
                <div class="px-3 py-2.5 sm:px-4">
                    <div class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2">
                        <p class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-900">
                            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ __('Store these recovery codes in a secure place. Each code can only be used once.') }}
                        </p>
                        <div class="mt-2 grid grid-cols-2 gap-1.5 font-mono text-xs text-amber-950 sm:grid-cols-4">
                            @foreach (session('recovery_codes') as $code)
                                <span class="rounded bg-white/60 px-1.5 py-0.5">{{ $code }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endif
        </div>
