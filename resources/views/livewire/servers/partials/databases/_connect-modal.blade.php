{{-- Connection details for a database on this server. Site-free counterpart to
     the site tab's Connect panel, which is keyed by SiteBinding and so cannot
     address a database no site has attached.

     The password is never rendered — it travels only through the one-time
     credential-share link, same rule as the site panel. --}}
@php $connect = $this->databaseConnectPanel(); @endphp

<x-modal name="server-database-connect" max-width="2xl" focusable>
    <div class="max-h-[85vh] overflow-y-auto p-6">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="text-base font-semibold text-brand-ink">{{ __('Connect to this database') }}</h3>
                <p class="mt-1 text-sm text-brand-moss">{{ __('Works with TablePlus, DBeaver, DataGrip, psql — any client.') }}</p>
            </div>
            <button aria-label="{{ __('Close') }}" type="button" wire:click="closeDatabaseConnect" class="dply-hit-44 shrink-0 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink">
                <x-heroicon-o-x-mark class="h-5 w-5" />
            </button>
        </div>

        @if (! $connect)
            <div class="mt-4 rounded-lg border border-brand-ink/10 bg-brand-sand/20 px-4 py-3 text-xs text-brand-moss">
                {{ __('Select a database to see its connection details.') }}
            </div>
        @else
            @php
                $t = $connect['target'];
                $cmd = $connect['commands'];
                $labelCls = 'text-2xs font-semibold uppercase tracking-wide text-brand-moss';
                $valueCls = 'mt-0.5 break-all font-mono text-xs text-brand-ink';
            @endphp

            <dl class="mt-4 grid min-w-0 grid-cols-2 gap-x-4 gap-y-3 rounded-lg border border-brand-ink/10 bg-brand-sand/20 p-4 sm:grid-cols-4">
                <div class="min-w-0"><dt class="{{ $labelCls }}">{{ __('Host') }}</dt><dd class="{{ $valueCls }}">{{ $t->host }}</dd></div>
                <div class="min-w-0"><dt class="{{ $labelCls }}">{{ __('Port') }}</dt><dd class="{{ $valueCls }}">{{ $t->port }}</dd></div>
                <div class="min-w-0"><dt class="{{ $labelCls }}">{{ __('Database') }}</dt><dd class="{{ $valueCls }}">{{ $t->database }}</dd></div>
                <div class="min-w-0"><dt class="{{ $labelCls }}">{{ __('User') }}</dt><dd class="{{ $valueCls }}">{{ $t->username ?: '—' }}</dd></div>
            </dl>

            @unless ($connect['credentials_known'])
                <div class="mt-3 flex items-start gap-2 rounded-lg border border-amber-200/70 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    <x-heroicon-o-exclamation-triangle class="mt-0.5 h-4 w-4 shrink-0" />
                    <span>{{ __('This database was adopted from the server, so dply does not hold its password. Your client will prompt for it — or rotate the password to let dply manage it.') }}</span>
                </div>
            @endunless

            <div class="mt-4">
                <p class="{{ $labelCls }}">{{ __('Bind the tunnel on') }}</p>
                <div class="mt-1 flex flex-wrap items-center gap-3">
                    <input type="number" min="1024" max="65535" wire:model.blur="connectLocalPort" class="w-28 rounded-lg border-brand-ink/15 bg-white font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-brand-sage" />
                    <input type="text" wire:model.blur="connectSshKeyPath" placeholder="~/.ssh/id_ed25519" class="min-w-0 flex-1 rounded-lg border-brand-ink/15 bg-white font-mono text-xs text-brand-ink focus:border-brand-sage focus:ring-brand-sage" />
                </div>
                <p class="mt-1 text-2xs text-brand-moss">{{ __('The database listens on loopback only, so a client reaches it through an SSH tunnel to this server.') }}</p>
            </div>

            @foreach ([
                ['label' => __('1 · Open the tunnel'), 'value' => $cmd['tunnel']],
                ['label' => __('2 · Connect'), 'value' => $cmd['connect']],
                ['label' => __('Connection URI'), 'value' => $cmd['uri']],
            ] as $row)
                <div class="mt-3" x-data="{ copied: false }">
                    <p class="{{ $labelCls }}">{{ $row['label'] }}</p>
                    <div class="mt-1 flex items-start gap-2">
                        <code class="min-w-0 flex-1 break-all rounded-lg border border-brand-ink/10 bg-brand-cream/40 px-3 py-2 font-mono text-xs text-brand-ink">{{ $row['value'] }}</code>
                        <x-secondary-button size="xs" type="button" class="shrink-0"
                            x-on:click="navigator.clipboard.writeText(@js($row['value'])); copied = true; setTimeout(() => copied = false, 2000)">
                            <span x-show="!copied">{{ __('Copy') }}</span>
                            <span x-show="copied" x-cloak class="text-brand-forest">{{ __('Copied') }}</span>
                        </x-secondary-button>
                    </div>
                </div>
            @endforeach
        @endif

        <div class="mt-5 flex justify-end border-t border-brand-ink/10 pt-3">
            <x-secondary-button type="button" wire:click="closeDatabaseConnect">{{ __('Close') }}</x-secondary-button>
        </div>
    </div>
</x-modal>
