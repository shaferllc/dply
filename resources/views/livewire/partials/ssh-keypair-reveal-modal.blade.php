{{--
  One-shot private key dialog after server-side Ed25519 generation.
  @param string $listenEvent Livewire browser event (avoid duplicate listeners on pages that embed multiple generators).
  @param string $revealContext 'server' | 'profile' — copy hint only.
--}}
@php
    $listenEvent = $listenEvent ?? 'dply-ssh-keypair-generated';
    $revealContext = $revealContext ?? 'server';
    $idPrefix = $revealContext === 'profile' ? 'sk-pr' : 'sk-ws';
@endphp
@teleport('body')
    <div
        wire:ignore
        class="relative z-[110]"
        x-data="{
            revealOpen: false,
            privateKey: '',
            publicKey: '',
            copiedPrivate: false,
            copiedPublic: false,
            acknowledged: false,
            openFromLivewire(detail) {
                const d = detail || {};
                this.privateKey = d.privateKey ?? d.private_key ?? '';
                this.publicKey = d.publicKey ?? d.public_key ?? '';
                this.copiedPrivate = false;
                this.copiedPublic = false;
                this.acknowledged = false;
                this.revealOpen = true;
            },
            async copyPrivate() {
                try {
                    await navigator.clipboard.writeText(this.privateKey);
                    this.copiedPrivate = true;
                } catch (e) {}
            },
            async copyPublic() {
                try {
                    await navigator.clipboard.writeText(this.publicKey);
                    this.copiedPublic = true;
                } catch (e) {}
            },
            downloadPrivate() {
                const blob = new Blob([this.privateKey], { type: 'text/plain;charset=utf-8' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = 'id_ed25519';
                a.rel = 'noopener';
                document.body.appendChild(a);
                a.click();
                a.remove();
                URL.revokeObjectURL(url);
            },
            closeReveal() {
                if (! this.acknowledged) {
                    return;
                }
                this.revealOpen = false;
                this.privateKey = '';
                this.publicKey = '';
            },
        }"
        @if ($listenEvent === 'dply-ssh-profile-keypair-generated')
            x-on:dply-ssh-profile-keypair-generated.window="openFromLivewire($event.detail)"
        @else
            x-on:dply-ssh-keypair-generated.window="openFromLivewire($event.detail)"
        @endif
        x-on:keydown.escape.window="if (revealOpen && acknowledged) closeReveal()"
    >
        <div
            x-show="revealOpen"
            x-cloak
            class="fixed inset-0 flex items-end justify-center p-4 sm:items-center sm:p-6"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $idPrefix }}-ssh-keypair-reveal-title"
        >
            <div class="fixed inset-0 z-0 bg-brand-ink/50 backdrop-blur-sm" aria-hidden="true"></div>
            <div class="relative z-10 my-auto w-full max-w-lg overflow-hidden dply-modal-panel">
                <div class="border-b border-brand-ink/10 px-6 py-5">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Private key') }}</p>
                    <h2 id="{{ $idPrefix }}-ssh-keypair-reveal-title" class="mt-2 text-xl font-semibold text-brand-ink">{{ __('Copy your private key now') }}</h2>
                    <p class="mt-2 text-sm leading-6 text-brand-moss">
                        @if ($revealContext === 'profile')
                            {{ __('This private key is shown once in this dialog. Dply does not store it. Save it locally, then save the public key to your profile with the button below.') }}
                        @else
                            {{ __('This private key is shown once in this dialog. Dply does not store it. Add it to your SSH agent or save it as a file, then use “Add SSH key” and “Sync authorized_keys” below.') }}
                        @endif
                    </p>
                </div>
                <div class="space-y-4 px-6 py-5">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-brand-ink" for="{{ $idPrefix }}-ssh-keypair-private">{{ __('Private key (OpenSSH)') }}</label>
                        <textarea
                            id="{{ $idPrefix }}-ssh-keypair-private"
                            readonly
                            rows="8"
                            class="block w-full resize-y rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-[11px] leading-relaxed text-brand-ink"
                            x-model="privateKey"
                        ></textarea>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <button type="button" class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40" @click="copyPrivate()">
                                <span x-show="!copiedPrivate">{{ __('Copy private key') }}</span>
                                <span x-show="copiedPrivate" x-cloak>{{ __('Copied') }}</span>
                            </button>
                            <button type="button" class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40" @click="downloadPrivate()">
                                {{ __('Download') }}
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium text-brand-ink" for="{{ $idPrefix }}-ssh-keypair-public">{{ __('Public key (already filled in the form)') }}</label>
                        <textarea
                            id="{{ $idPrefix }}-ssh-keypair-public"
                            readonly
                            rows="3"
                            class="block w-full resize-y rounded-xl border border-brand-ink/15 bg-white px-3 py-2 font-mono text-[11px] text-brand-ink"
                            x-model="publicKey"
                        ></textarea>
                        <button type="button" class="mt-2 inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40" @click="copyPublic()">
                            <span x-show="!copiedPublic">{{ __('Copy public key') }}</span>
                            <span x-show="copiedPublic" x-cloak>{{ __('Copied') }}</span>
                        </button>
                    </div>
                    <label class="flex cursor-pointer items-start gap-3">
                        <input type="checkbox" class="mt-1 rounded border-brand-ink/20 text-brand-ink focus:ring-brand-sage" x-model="acknowledged" />
                        <span class="text-sm leading-relaxed text-brand-moss">{{ __('I have saved my private key somewhere secure.') }}</span>
                    </label>
                </div>
                <div class="flex justify-end gap-3 border-t border-brand-ink/10 px-6 py-4">
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
