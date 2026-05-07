{{--
  One-shot HTTP basic-auth password reveal dialog.
  Listens for the `dply-basic-auth-password-revealed` browser event Livewire dispatches
  after a server-side rotate, then shows the plaintext with copy buttons. Plaintext lives
  in Alpine local state only — never round-trips back to Livewire.
--}}
<script>
    (function () {
        if (window.dplyBasicAuthPasswordReveal) {
            return;
        }
        window.dplyBasicAuthPasswordReveal = () => ({
            revealOpen: false,
            username: '',
            path: '',
            password: '',
            copiedPassword: false,
            copiedHeader: false,
            acknowledged: false,
            openFromLivewire(detail) {
                const d = detail || {};
                this.username = d.username ?? '';
                this.path = d.path ?? '/';
                this.password = d.password ?? '';
                this.copiedPassword = false;
                this.copiedHeader = false;
                this.acknowledged = false;
                this.revealOpen = true;
            },
            authHeader() {
                try {
                    return 'Basic ' + btoa(`${this.username}:${this.password}`);
                } catch (e) {
                    return '';
                }
            },
            async copyPassword() {
                try { await navigator.clipboard.writeText(this.password); this.copiedPassword = true; } catch (e) {}
            },
            async copyHeader() {
                try { await navigator.clipboard.writeText(this.authHeader()); this.copiedHeader = true; } catch (e) {}
            },
            closeReveal() {
                if (!this.acknowledged) return;
                this.cancelReveal();
            },
            cancelReveal() {
                this.revealOpen = false;
                this.username = '';
                this.path = '';
                this.password = '';
                this.copiedPassword = false;
                this.copiedHeader = false;
                this.acknowledged = false;
            },
        });
    })();
</script>

@teleport('body')
    <div
        wire:ignore
        class="relative z-[110]"
        x-data="dplyBasicAuthPasswordReveal()"
        x-on:dply-basic-auth-password-revealed.window="openFromLivewire($event.detail?.[0] ?? $event.detail)"
        x-on:keydown.escape.window="if (revealOpen) cancelReveal()"
    >
        <div
            x-show="revealOpen"
            x-cloak
            class="fixed inset-0 flex items-end justify-center p-4 sm:items-center sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="ba-pw-reveal-title"
        >
            <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" aria-hidden="true"></div>
            <div class="relative z-10 my-auto w-full max-w-xl overflow-hidden dply-modal-panel">
                <div class="flex items-start justify-between gap-4 border-b border-brand-ink/10 px-6 py-5">
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Password rotated') }}</p>
                        <h2 id="ba-pw-reveal-title" class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Copy the new password now') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-brand-moss">
                            {{ __('Dply only stores a hash. This dialog shows the plaintext once — copy it before you close.') }}
                        </p>
                    </div>
                    <button
                        type="button"
                        @click="cancelReveal()"
                        class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-brand-ink/10 bg-white text-brand-mist transition hover:border-brand-ink/20 hover:bg-brand-sand/40 hover:text-brand-ink"
                        aria-label="{{ __('Close') }}"
                    >
                        <x-heroicon-m-x-mark class="h-5 w-5" aria-hidden="true" />
                    </button>
                </div>

                <div class="space-y-4 px-6 py-5">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Username') }}</p>
                            <p class="mt-1 break-all rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5 font-mono text-sm text-brand-ink" x-text="username"></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Path') }}</p>
                            <p class="mt-1 break-all rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-3 py-1.5 font-mono text-sm text-brand-ink" x-text="path"></p>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="ba-pw-reveal-input">
                            <span>{{ __('New password') }}</span>
                            <button type="button" class="text-xs font-medium text-brand-sage hover:underline" @click="copyPassword()">
                                <span x-show="!copiedPassword">{{ __('Copy') }}</span>
                                <span x-show="copiedPassword" x-cloak>{{ __('Copied') }}</span>
                            </button>
                        </label>
                        <input
                            id="ba-pw-reveal-input"
                            readonly
                            type="text"
                            class="block w-full rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-sm text-brand-ink"
                            x-bind:value="password"
                            @click="$event.target.select()"
                        />
                    </div>

                    <div class="rounded-xl border border-brand-sage/25 bg-brand-sage/5 px-4 py-3">
                        <div class="flex items-center justify-between gap-3">
                            <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Authorization header') }}</p>
                            <button type="button" class="text-xs font-semibold text-brand-forest hover:underline" @click="copyHeader()">
                                <span x-show="!copiedHeader">{{ __('Copy header') }}</span>
                                <span x-show="copiedHeader" x-cloak>{{ __('Copied') }}</span>
                            </button>
                        </div>
                        <p class="mt-1 break-all font-mono text-[11px] text-brand-moss" x-text="authHeader()"></p>
                        <p class="mt-1 text-[11px] text-brand-moss">{{ __('Drop into a curl -H or proxy config to test the credential.') }}</p>
                    </div>

                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="checkbox" class="mt-1 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage" x-model="acknowledged" />
                        <span class="text-sm leading-relaxed text-brand-moss">{{ __('I have copied this password somewhere safe.') }}</span>
                    </label>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40 disabled:cursor-not-allowed disabled:opacity-40"
                        @click="closeReveal()"
                        :disabled="!acknowledged"
                    >
                        {{ __('Done') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
@endteleport
