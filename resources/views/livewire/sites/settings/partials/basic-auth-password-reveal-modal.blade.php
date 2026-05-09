{{--
  HTTP basic-auth rotate dialog.

  Opened from a row's Rotate button via the `dply-basic-auth-password-rotate-prompt`
  browser event with { user_id, username, path, host }. The operator either keeps
  the random pre-fill, types their own, or clicks Generate; Show/Hide and Copy let
  them grab the plaintext before submit. On Submit we call Livewire's
  rotateBasicAuthPassword(userId, plaintext) — server hashes, persists, and
  triggers the webserver-config apply via finalizeRoutingMutation. The dialog
  closes immediately after the call resolves: we never round-trip the plaintext
  back to the browser, so the operator must Copy/Show before submitting if they
  want a record of it.
--}}
<script>
    (function () {
        if (window.dplyBasicAuthPasswordReveal) {
            return;
        }
        window.dplyBasicAuthPasswordReveal = () => ({
            revealOpen: false,
            userId: '',
            username: '',
            path: '',
            password: '',
            host: '',
            showPassword: false,
            submitting: false,
            copied: false,
            randomPassword(length = 20) {
                // 94-printable ASCII (excluding space) — parity with Laravel's Str::password.
                const charset = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!#$%&()*+,-./:;<=>?@[]^_{|}~';
                const buf = new Uint32Array(length);
                crypto.getRandomValues(buf);
                let out = '';
                for (let i = 0; i < length; i++) out += charset[buf[i] % charset.length];
                return out;
            },
            openPending(detail) {
                const d = detail || {};
                this.userId = d.user_id ?? '';
                this.username = d.username ?? '';
                this.path = d.path ?? '/';
                this.host = d.host ?? '';
                // Pre-fill with a random password so the operator can either keep it,
                // edit it, or replace it entirely before submitting.
                this.password = this.randomPassword(20);
                this.showPassword = false;
                this.submitting = false;
                this.copied = false;
                this.revealOpen = true;
            },
            regeneratePending() {
                this.password = this.randomPassword(20);
                this.copied = false;
            },
            async copyPassword() {
                if (!this.password) return;
                try { await navigator.clipboard.writeText(this.password); this.copied = true; } catch (e) {}
            },
            async submitRotate($wire) {
                if (!this.userId || this.submitting) return;
                if (this.password.length < 8 || this.password.length > 255) return;
                this.submitting = true;
                try {
                    await $wire.call('rotateBasicAuthPassword', this.userId, this.password);
                    // Server-side validation passes if we made it here; close out.
                    this.cancelReveal();
                } catch (e) {
                    // Error toasts are surfaced by Livewire; reset so the operator
                    // can retry without re-opening the dialog.
                    this.submitting = false;
                }
            },
            cancelReveal() {
                this.revealOpen = false;
                this.userId = '';
                this.username = '';
                this.path = '';
                this.password = '';
                this.host = '';
                this.showPassword = false;
                this.submitting = false;
                this.copied = false;
            },
        });
    })();
</script>

@teleport('body')
    <div
        wire:ignore
        class="relative z-[110]"
        x-data="dplyBasicAuthPasswordReveal()"
        x-on:dply-basic-auth-password-rotate-prompt.window="openPending($event.detail?.[0] ?? $event.detail)"
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
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Rotate password') }}</p>
                        <h2 id="ba-pw-reveal-title" class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Set a new password') }}</h2>
                        <p class="mt-2 text-sm leading-6 text-brand-moss">
                            {{ __('Set a new password for this user, or keep the random one we generated. The current password stops working as soon as the webserver config reapplies — copy this value before you submit if you need a record.') }}
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
                        <label class="mb-1 flex items-center justify-between text-sm font-medium text-brand-ink" for="ba-pw-pending-input">
                            <span>{{ __('New password') }}</span>
                            <span class="flex items-center gap-3 text-xs">
                                <button type="button" class="font-medium text-brand-sage hover:underline disabled:opacity-50 disabled:cursor-not-allowed"
                                    @click="copyPassword()" :disabled="!password">
                                    <span x-show="!copied">{{ __('Copy') }}</span>
                                    <span x-show="copied" x-cloak>{{ __('Copied') }}</span>
                                </button>
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="showPassword = !showPassword">
                                    <span x-show="!showPassword">{{ __('Show') }}</span>
                                    <span x-show="showPassword" x-cloak>{{ __('Hide') }}</span>
                                </button>
                                <button type="button" class="font-medium text-brand-sage hover:underline" @click="regeneratePending()">{{ __('Generate') }}</button>
                            </span>
                        </label>
                        <input
                            id="ba-pw-pending-input"
                            x-bind:type="showPassword ? 'text' : 'password'"
                            x-model="password"
                            x-on:input="copied = false"
                            class="block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2 font-mono text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-2 focus:ring-brand-sage/30"
                            autocomplete="new-password"
                            spellcheck="false"
                        />
                        <p class="mt-1 text-[11px] text-brand-moss">{{ __('8–255 characters. Click Generate for a fresh random secret.') }}</p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
                    <button
                        type="button"
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink hover:bg-brand-sand/40"
                        @click="cancelReveal()"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:cursor-not-allowed disabled:opacity-60"
                        @click="submitRotate($wire)"
                        :disabled="submitting || !userId || password.length < 8 || password.length > 255"
                    >
                        <span x-show="!submitting">{{ __('Rotate password') }}</span>
                        <span x-show="submitting" x-cloak class="inline-flex items-center gap-2">
                            <x-spinner variant="cream" size="sm" />
                            {{ __('Rotating…') }}
                        </span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endteleport
