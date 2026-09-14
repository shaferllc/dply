{{--
  One-shot private key dialog after server-side Ed25519 generation.
  @param string $listenEvent Livewire browser event (avoid duplicate listeners on pages that embed multiple generators).
  @param string $revealContext 'server' | 'profile' — copy hint only.
--}}
@php
    $listenEvent = $listenEvent ?? 'dply-ssh-keypair-generated';
    $revealContext = $revealContext ?? 'server';
    $idPrefix = $revealContext === 'profile' ? 'sk-pr' : 'sk-ws';
    $alpineFactory = $revealContext === 'profile' ? 'dplySshKeypairRevealProfile' : 'dplySshKeypairReveal';
@endphp

{{-- The Alpine factory ({{ $alpineFactory }}) is registered in resources/js/ssh-keypair-reveal.js.
     It used to be an inline <script> here, but the SSH keys page is lazy: Livewire does not run
     <script> tags that arrive after load, so the factory never existed and this dialog never
     opened. --}}

@teleport('body')
    <div
        wire:ignore
        class="relative z-[110]"
        x-data="{{ $alpineFactory }}()"
        @if ($listenEvent === 'dply-ssh-profile-keypair-generated')
            x-on:dply-ssh-profile-keypair-generated.window="openFromLivewire($event.detail)"
        @else
            x-on:dply-ssh-keypair-generated.window="openFromLivewire($event.detail)"
        @endif
        x-on:keydown.escape.window="if (revealOpen) cancelReveal()"
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
            <div class="relative z-10 my-auto w-full max-w-3xl overflow-hidden dply-modal-panel">
                <div class="flex items-start justify-between gap-4 border-b border-brand-ink/10 px-6 py-5">
                    <div class="min-w-0 flex-1">
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
                    <div>
                        <label class="mb-1 block text-sm font-medium text-brand-ink" for="{{ $idPrefix }}-ssh-keypair-private">{{ __('Private key (OpenSSH)') }}</label>
                        <textarea
                            id="{{ $idPrefix }}-ssh-keypair-private"
                            readonly
                            rows="8"
                            class="block w-full resize-y rounded-xl border border-brand-ink/15 bg-brand-cream/50 px-3 py-2 font-mono text-xs leading-relaxed text-brand-ink"
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

                    {{-- Install on local machine — paste-ready bash one-liner. --}}
                    <div class="rounded-xl border border-brand-sage/25 bg-brand-sage/5 px-4 py-3">
                        <p class="text-xs font-semibold uppercase tracking-[0.16em] text-brand-mist">{{ __('Install on your machine') }}</p>
                        <p x-show="! access" class="mt-1 text-xs leading-5 text-brand-moss">{{ __('Copies a one-liner that writes the private key to ~/.ssh/, chmods it 600, and (on macOS) adds it to your login keychain. Paste it in your terminal.') }}</p>
                        <p x-show="access" x-cloak class="mt-1 text-xs leading-5 text-brand-moss">
                            {{ __('dply installed the public key on the server for you (the sync is running). Paste this once: it saves the key to ~/.ssh/ and adds an SSH config entry that offers only this key, so a busy agent can’t hit “Too many authentication failures”. Then just run') }}
                            <code class="font-mono text-brand-ink" x-text="access ? 'ssh ' + access.alias : ''"></code>.
                        </p>
                        <div class="mt-3 grid gap-2 sm:grid-cols-[1fr_auto]">
                            <label class="block">
                                <span class="text-xs font-medium text-brand-moss">{{ __('Filename in ~/.ssh/') }}</span>
                                <div class="mt-1 flex items-stretch overflow-hidden rounded-lg border border-brand-ink/15 bg-white">
                                    <span class="flex shrink-0 items-center bg-brand-sand/30 px-2 font-mono text-xs text-brand-moss">~/.ssh/</span>
                                    <input
                                        type="text"
                                        x-model="installFilename"
                                        class="block w-full border-0 bg-transparent px-2 py-1.5 font-mono text-[12px] focus:outline-none focus:ring-0"
                                        placeholder="id_ed25519_dply"
                                    />
                                </div>
                            </label>
                            <button
                                type="button"
                                @click="copyInstallCommand()"
                                class="inline-flex items-center justify-center gap-1.5 self-end rounded-lg border border-brand-sage/40 bg-white px-3 py-1.5 text-xs font-semibold text-brand-forest shadow-sm hover:bg-brand-sage/10"
                            >
                                <span x-show="!copiedInstall" class="inline-flex items-center gap-1.5">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3a2.25 2.25 0 00-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" /></svg>
                                    {{ __('Copy install command') }}
                                </span>
                                <span x-show="copiedInstall" x-cloak class="inline-flex items-center gap-1.5 text-emerald-700">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                    {{ __('Copied — paste in terminal') }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-brand-ink" for="{{ $idPrefix }}-ssh-keypair-public">{{ __('Public key (already filled in the form)') }}</label>
                        <textarea
                            id="{{ $idPrefix }}-ssh-keypair-public"
                            readonly
                            rows="3"
                            class="block w-full resize-y rounded-xl border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs text-brand-ink"
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
