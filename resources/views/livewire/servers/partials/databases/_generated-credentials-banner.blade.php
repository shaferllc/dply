@if ($generated_database_credentials)
    @php
        $plainPassword = $generated_database_credentials['password'] ?? null;
        $passwordHidden = (bool) ($generated_database_credentials['password_hidden'] ?? false);
        $credentialsEmailed = (bool) ($generated_database_credentials['credentials_emailed'] ?? false);
        $showPasswordOnce = filled($plainPassword) && ! $passwordHidden;
    @endphp
    <section
        class="dply-card overflow-hidden"
        @if ($showPasswordOnce)
            x-data="{
                copied: false,
                async copyPassword() {
                    const v = @js($plainPassword);
                    if (! v) return;
                    try {
                        await navigator.clipboard.writeText(v);
                        this.copied = true;
                        setTimeout(() => this.copied = false, 1800);
                    } catch (e) {}
                },
                hidePassword() {
                    $wire.hideGeneratedDatabasePassword();
                },
            }"
            x-init="setTimeout(() => hidePassword(), 90000)"
        @endif
    >
        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <x-icon-badge>
                <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0 flex-1">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Just created') }}</p>
                <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('New database credentials') }}</h3>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    @if ($credentialsEmailed)
                        {{ __('Save these now. Credentials for :name were emailed to you and shown here once — the password hides automatically.', ['name' => $generated_database_credentials['name']]) }}
                    @else
                        {{ __('Save these now. Dply generated credentials for :name and shows the password here once — copy it before it hides.', ['name' => $generated_database_credentials['name']]) }}
                    @endif
                </p>
            </div>
            <button type="button" wire:click="dismissGeneratedDatabaseCredentials" class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                <x-heroicon-m-x-mark class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ __('Dismiss') }}
            </button>
        </div>
        <dl class="grid gap-3 p-6 sm:grid-cols-2 sm:p-7">
            <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Database') }}</dt>
                <dd class="mt-0.5 font-mono text-sm font-semibold text-brand-ink">{{ $generated_database_credentials['name'] }}</dd>
            </div>
            <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Engine') }}</dt>
                <dd class="mt-0.5 text-sm font-semibold text-brand-ink">{{ $engineLabels[$generated_database_credentials['engine']] ?? ucfirst((string) $generated_database_credentials['engine']) }}</dd>
            </div>
            @if (filled($generated_database_credentials['username']))
                <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Username') }}</dt>
                    <dd class="mt-0.5 font-mono text-sm font-semibold text-brand-ink">{{ $generated_database_credentials['username'] }}</dd>
                    @if ($generated_database_credentials['username_generated'])
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Generated for you.') }}</p>
                    @endif
                </div>
            @endif
            @if (filled($plainPassword) || $passwordHidden)
                <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Password') }}</dt>
                        @if ($showPasswordOnce)
                            <span class="flex shrink-0 items-center gap-3 text-xs">
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="copyPassword()">
                                    <span x-show="!copied">{{ __('Copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                </button>
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="hidePassword()">{{ __('Hide') }}</button>
                            </span>
                        @endif
                    </div>
                    @if ($showPasswordOnce)
                        <dd class="mt-0.5 break-all font-mono text-sm font-semibold text-brand-ink">{{ $plainPassword }}</dd>
                    @else
                        <dd class="mt-0.5 font-mono text-sm font-semibold tracking-widest text-brand-mist">••••••••••••••••</dd>
                        <p class="mt-1 text-[11px] text-brand-mist">
                            @if ($credentialsEmailed)
                                {{ __('Password hidden. Check your email or reveal it from the Credentials column on the database row.') }}
                            @else
                                {{ __('Password hidden. Reveal it anytime from the Credentials column on the database row.') }}
                            @endif
                        </p>
                    @endif
                    @if ($generated_database_credentials['password_generated'] && $showPasswordOnce)
                        <p class="mt-1 text-[11px] text-brand-mist">{{ __('Generated for you.') }}</p>
                    @endif
                </div>
            @endif
            @if ($generated_database_credentials['engine'] === 'sqlite' && filled($generated_database_credentials['host'] ?? null))
                <div class="rounded-xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm sm:col-span-2">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('File path') }}</dt>
                    <dd class="mt-0.5 break-all font-mono text-sm font-semibold text-brand-ink">{{ $generated_database_credentials['host'] }}</dd>
                </div>
            @endif
        </dl>
    </section>
@endif
