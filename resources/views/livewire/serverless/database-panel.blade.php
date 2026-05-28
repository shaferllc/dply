<div @if ($state === 'provisioning') wire:poll.10s @endif class="dply-card p-6 sm:p-8 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-brand-moss">{{ __('Database') }}</p>
            <h2 class="mt-1 text-base font-bold text-brand-ink">{{ __('Managed Database') }}</h2>
            <p class="mt-1 text-sm text-brand-moss">{{ __('A DigitalOcean Managed Database, with the connection wired into this function\'s environment.') }}</p>
        </div>
        @if ($state !== '')
            <span @class([
                'inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold',
                'bg-brand-forest/15 text-brand-forest' => $state === 'online',
                'bg-brand-gold/20 text-brand-ink' => $state === 'provisioning',
                'bg-rose-100 text-rose-700' => $state === 'error',
            ])>
                {{ ['provisioning' => __('Provisioning'), 'online' => __('Online'), 'error' => __('Error')][$state] ?? $state }}
            </span>
        @endif
    </div>

    @if ($state === '')
        {{-- No database yet — provisioning form. --}}
        <div class="grid gap-3 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-semibold text-brand-ink">{{ __('Engine') }}</label>
                <select wire:model="engine" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                    <option value="pg">{{ __('PostgreSQL') }}</option>
                    <option value="mysql">{{ __('MySQL') }}</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-semibold text-brand-ink">{{ __('Size') }}</label>
                <select wire:model="size" class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-gold focus:ring-1 focus:ring-brand-gold/40 focus:outline-none">
                    <option value="db-s-1vcpu-1gb">{{ __('1 vCPU · 1 GB') }}</option>
                    <option value="db-s-1vcpu-2gb">{{ __('1 vCPU · 2 GB') }}</option>
                    <option value="db-s-2vcpu-4gb">{{ __('2 vCPU · 4 GB') }}</option>
                </select>
            </div>
        </div>
        <div class="rounded-xl border border-brand-ink/10 bg-brand-sand/30 px-4 py-3 text-sm">
            <span class="font-semibold text-brand-ink">{{ __('Estimated cost') }}</span>
            <span class="text-brand-ink">≈ ${{ number_format($estimate) }}/mo</span>
            <span class="text-brand-moss">— {{ __('billed by DigitalOcean to your account.') }}</span>
        </div>
        <button type="button" wire:click="provision" wire:loading.attr="disabled"
                class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
            {{ __('Provision database') }}
        </button>
        <p class="text-xs text-brand-moss">{{ __('The cluster takes a few minutes to come online.') }}</p>
    @elseif ($state === 'provisioning')
        <div class="flex items-center gap-3 rounded-xl border border-brand-gold/30 bg-brand-gold/10 px-4 py-3 text-sm text-brand-ink">
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4z"/>
            </svg>
            {{ __('The :engine cluster is being created. This page updates automatically.', ['engine' => $database['engine'] === 'mysql' ? 'MySQL' : 'PostgreSQL']) }}
        </div>
    @elseif ($state === 'online')
        <dl class="grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss/70">{{ __('Host') }}</dt>
                <dd class="mt-0.5 truncate font-mono text-sm text-brand-ink">{{ $database['host'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss/70">{{ __('Port') }}</dt>
                <dd class="mt-0.5 font-mono text-sm text-brand-ink">{{ $database['port'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss/70">{{ __('Database') }}</dt>
                <dd class="mt-0.5 font-mono text-sm text-brand-ink">{{ $database['database'] ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-brand-moss/70">{{ __('Username') }}</dt>
                <dd class="mt-0.5 font-mono text-sm text-brand-ink">{{ $database['username'] ?? '—' }}</dd>
            </div>
        </dl>
        <div class="rounded-xl border border-brand-forest/20 bg-brand-forest/10 px-4 py-3 text-sm text-brand-forest">
            {{ __('The connection (including the password) is stored in this function\'s environment as DB_* variables. Redeploy the function to start using it.') }}
            @if ($database['pooled'] ?? false)
                <span class="mt-1 block text-brand-forest/80">{{ __('Routed through a transaction-mode connection pool — safe for serverless cold starts.') }}</span>
            @endif
        </div>
    @elseif ($state === 'error')
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
            {{ $database['error'] ?? __('The database could not be provisioned.') }}
        </div>
        <button type="button" wire:click="provision" wire:loading.attr="disabled"
                class="inline-flex items-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest disabled:opacity-70">
            {{ __('Try again') }}
        </button>
    @endif
</div>
