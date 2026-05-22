<div class="dply-page-shell space-y-6 py-8">
    <header class="space-y-1">
        <p class="text-xs font-semibold uppercase tracking-wide text-brand-moss">
            {{ __('Recover servers') }}
        </p>
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Import from DigitalOcean') }}</h1>
        <p class="text-sm text-brand-moss">
            {{ __('Scan a DigitalOcean account, find droplets, and adopt them as dply servers. Useful for recovering servers whose dply rows are gone but whose droplets are still running.') }}
        </p>
    </header>

    @if (session('status'))
        <div class="rounded-2xl border border-brand-forest/20 bg-brand-forest/5 p-4 text-sm text-brand-forest">
            {{ session('status') }}
        </div>
    @endif

    <section class="rounded-3xl border border-brand-ink/10 bg-white p-6 shadow-sm">
        <h2 class="text-lg font-semibold text-brand-ink">{{ __('1. Pick a credential') }}</h2>

        @if ($credentials->isEmpty())
            <p class="mt-2 text-sm text-brand-moss">
                {{ __('No DigitalOcean credentials in this organization. Add one in') }}
                <a href="{{ route('credentials.index') }}" wire:navigate class="font-semibold text-brand-sage hover:underline">{{ __('Credentials') }}</a>{{ __(' first.') }}
            </p>
        @else
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <select wire:model="credentialId" class="block min-w-[18rem] rounded-lg border-brand-ink/15 bg-white py-2 text-sm">
                    <option value="">{{ __('— select —') }}</option>
                    @foreach ($credentials as $c)
                        <option value="{{ $c->id }}">{{ $c->name ?: $c->id }}</option>
                    @endforeach
                </select>
                <button
                    type="button"
                    wire:click="scan"
                    wire:loading.attr="disabled"
                    wire:target="scan"
                    class="inline-flex h-10 items-center gap-2 rounded-lg bg-brand-ink px-4 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-60"
                >
                    <span wire:loading.remove wire:target="scan">{{ __('Scan droplets') }}</span>
                    <span wire:loading wire:target="scan">{{ __('Scanning…') }}</span>
                </button>
            </div>
        @endif

        @if ($scanError !== '')
            <p class="mt-3 rounded-lg border border-brand-rust/30 bg-brand-rust/5 p-3 text-xs text-brand-rust">{{ $scanError }}</p>
        @endif
    </section>

    @if (! empty($droplets))
        <section class="rounded-3xl border border-brand-ink/10 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-semibold text-brand-ink">{{ __('2. Adopt droplets') }}</h2>
            <p class="mt-1 text-xs text-brand-moss">
                {{ __('Found :count droplet(s). Already-imported droplets are disabled.', ['count' => count($droplets)]) }}
            </p>

            <ul class="mt-4 divide-y divide-brand-ink/10">
                @foreach ($droplets as $d)
                    <li class="flex flex-wrap items-center gap-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold text-brand-ink">{{ $d['name'] ?? '—' }}</p>
                            <p class="mt-0.5 text-xs text-brand-moss font-mono">
                                {{ $d['_public_ipv4'] ?? '—' }}
                                · {{ $d['region']['slug'] ?? '—' }}
                                · {{ $d['size_slug'] ?? '—' }}
                                · {{ $d['status'] ?? '—' }}
                            </p>
                        </div>
                        @if ($d['_already_imported'] ?? false)
                            <span class="inline-flex items-center gap-1 rounded-md bg-brand-ink/5 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-brand-ink/70 ring-1 ring-brand-ink/10">
                                {{ __('Already imported') }}
                            </span>
                        @else
                            <button
                                type="button"
                                wire:click="openAdoptModal({{ (int) ($d['id'] ?? 0) }})"
                                class="inline-flex h-9 items-center gap-1 rounded-lg bg-brand-sage px-3 text-xs font-semibold text-white shadow-sm hover:bg-brand-forest"
                            >
                                {{ __('Adopt') }}
                                <x-heroicon-o-arrow-right class="h-3 w-3" />
                            </button>
                        @endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($generatedPublicKey !== '')
        <section class="rounded-3xl border-2 border-brand-forest/30 bg-brand-forest/5 p-6 shadow-sm">
            <div class="flex items-start gap-3">
                <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-forest text-white">
                    <x-heroicon-o-check class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Server imported. One more step.') }}</h2>
                    <p class="mt-1 text-sm text-brand-moss">
                        {{ __('A fresh ed25519 keypair was generated. The private key is stored encrypted on dply. Paste the public key below into') }} <code class="font-mono">~/.ssh/authorized_keys</code> {{ __('on the droplet (root account, or whichever SSH user you set). The DigitalOcean web console works if you can\'t SSH in directly yet.') }}
                    </p>

                    <div class="mt-4">
                        <x-input-label :value="__('Public key')" />
                        <textarea
                            readonly
                            rows="3"
                            class="mt-1 block w-full rounded-lg border-brand-ink/15 bg-white font-mono text-xs"
                        >{{ $generatedPublicKey }}</textarea>
                        <p class="mt-2 text-xs text-brand-mist">{{ __('Select all & copy, or use the button below.') }}</p>
                        <button
                            type="button"
                            onclick="navigator.clipboard.writeText({{ json_encode($generatedPublicKey) }}); this.textContent = '{{ __('Copied') }}'; setTimeout(() => this.textContent = '{{ __('Copy to clipboard') }}', 1500)"
                            class="mt-2 inline-flex h-9 items-center gap-1 rounded-lg bg-brand-ink px-3 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest"
                        >{{ __('Copy to clipboard') }}</button>
                    </div>

                    <div class="mt-5 flex items-center justify-between border-t border-brand-forest/20 pt-4">
                        <button type="button" wire:click="dismissGeneratedKey" class="text-sm font-medium text-brand-moss hover:text-brand-ink">
                            {{ __('Stay here') }}
                        </button>
                        <a href="{{ $adoptedServerUrl }}" wire:navigate class="inline-flex h-10 items-center gap-2 rounded-lg bg-brand-forest px-4 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink">
                            {{ __('Open server') }}
                            <x-heroicon-o-arrow-right class="h-4 w-4" />
                        </a>
                    </div>
                </div>
            </div>
        </section>
    @endif

    @if ($adoptDropletId !== null)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-brand-ink/40 p-4">
            <div class="w-full max-w-lg rounded-3xl bg-white p-6 shadow-xl">
                <h3 class="text-lg font-semibold text-brand-ink">{{ __('Adopt droplet') }}</h3>
                <p class="mt-1 text-xs text-brand-moss">{{ __('dply needs SSH access to manage this server. Paste the matching private key — it will be stored encrypted.') }}</p>

                @if ($adoptError !== '')
                    <p class="mt-3 rounded-lg border border-brand-rust/30 bg-brand-rust/5 p-3 text-xs text-brand-rust">{{ $adoptError }}</p>
                @endif

                <form wire:submit="adopt" class="mt-4 space-y-4">
                    <div>
                        <x-input-label for="adopt_name" :value="__('Server name')" />
                        <x-text-input id="adopt_name" wire:model="adoptName" class="mt-1 block w-full font-mono text-sm" required />
                        <x-input-error :messages="$errors->get('adoptName')" class="mt-1" />
                    </div>

                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2">
                            <x-input-label for="adopt_ip" :value="__('Public IPv4')" />
                            <x-text-input id="adopt_ip" wire:model="adoptIp" class="mt-1 block w-full font-mono text-sm" required />
                            <x-input-error :messages="$errors->get('adoptIp')" class="mt-1" />
                        </div>
                        <div>
                            <x-input-label for="adopt_ssh_port" :value="__('SSH port')" />
                            <x-text-input id="adopt_ssh_port" wire:model="adoptSshPort" class="mt-1 block w-full font-mono text-sm" />
                            <x-input-error :messages="$errors->get('adoptSshPort')" class="mt-1" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="adopt_ssh_user" :value="__('SSH user')" />
                        <x-text-input id="adopt_ssh_user" wire:model="adoptSshUser" class="mt-1 block w-full font-mono text-sm" required />
                        <x-input-error :messages="$errors->get('adoptSshUser')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label :value="__('SSH key')" />
                        <div class="mt-1 grid grid-cols-2 gap-2">
                            <button
                                type="button"
                                wire:click="$set('adoptKeySource', 'paste')"
                                @class([
                                    'rounded-lg border px-3 py-2 text-xs font-semibold transition',
                                    'border-brand-sage bg-brand-sage/10 text-brand-forest' => $adoptKeySource === 'paste',
                                    'border-brand-ink/15 bg-white text-brand-moss hover:border-brand-sage/40' => $adoptKeySource !== 'paste',
                                ])
                            >{{ __('Paste existing key') }}</button>
                            <button
                                type="button"
                                wire:click="$set('adoptKeySource', 'generate')"
                                @class([
                                    'rounded-lg border px-3 py-2 text-xs font-semibold transition',
                                    'border-brand-sage bg-brand-sage/10 text-brand-forest' => $adoptKeySource === 'generate',
                                    'border-brand-ink/15 bg-white text-brand-moss hover:border-brand-sage/40' => $adoptKeySource !== 'generate',
                                ])
                            >{{ __('Generate a new key') }}</button>
                        </div>
                    </div>

                    @if ($adoptKeySource === 'paste')
                        <div>
                            <x-input-label for="adopt_ssh_key" :value="__('SSH private key (PEM)')" />
                            <textarea
                                id="adopt_ssh_key"
                                wire:model="adoptSshPrivateKey"
                                rows="6"
                                class="mt-1 block w-full rounded-lg border-brand-ink/15 font-mono text-xs"
                                placeholder="-----BEGIN OPENSSH PRIVATE KEY-----&#10;..."
                            ></textarea>
                            <x-input-error :messages="$errors->get('adoptSshPrivateKey')" class="mt-1" />
                        </div>
                    @else
                        <div class="rounded-lg border border-brand-sand bg-brand-sand/20 p-3 text-xs text-brand-moss">
                            <p class="font-semibold text-brand-ink">{{ __('A fresh ed25519 keypair will be generated when you click Import.') }}</p>
                            <p class="mt-1">{{ __('The private key is stored encrypted in dply. The matching public key will be shown on the next screen so you can add it to') }} <code class="font-mono">~/.ssh/authorized_keys</code> {{ __('on the droplet (use the DigitalOcean web console if dply can\'t SSH in yet).') }}</p>
                        </div>
                    @endif

                    <div class="flex items-center justify-between border-t border-brand-ink/10 pt-4">
                        <button type="button" wire:click="closeAdoptModal" class="text-sm font-medium text-brand-moss hover:text-brand-ink">
                            {{ __('Cancel') }}
                        </button>
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="adopt"
                            class="inline-flex h-10 items-center gap-2 rounded-lg bg-brand-ink px-4 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="adopt">{{ __('Import server') }}</span>
                            <span wire:loading wire:target="adopt">{{ __('Importing…') }}</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
