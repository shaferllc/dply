<div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 lg:px-8">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-brand-ink">{{ __('Coming-soon access') }}</h1>
            <p class="mt-1 max-w-2xl text-sm text-brand-moss">{{ __('IPs (and CIDR ranges) that see the full site while the coming-soon gate is on. Everyone else only sees the coming-soon page. Logged-in users always pass.') }}</p>
        </div>
        <span @class([
            'shrink-0 rounded-full px-2.5 py-1 text-[10px] font-bold uppercase tracking-wide',
            'bg-emerald-100 text-emerald-800' => $gateOn,
            'bg-brand-ink/[0.06] text-brand-moss' => ! $gateOn,
        ])>{{ $gateOn ? __('Gate active') : __('Gate off') }}</span>
    </div>

    {{-- Your IP --}}
    <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 px-4 py-3">
        <div class="min-w-0 text-sm">
            <span class="text-brand-moss">{{ __('Your current IP:') }}</span>
            <code class="ml-1 font-mono text-brand-ink">{{ $yourIp }}</code>
        </div>
        <button type="button" wire:click="addMyIp"
            class="shrink-0 rounded-lg border border-brand-forest bg-white px-3 py-1.5 text-xs font-semibold text-brand-forest hover:bg-brand-sage/10">
            {{ __('Allow my IP') }}
        </button>
    </div>

    {{-- Add form --}}
    <div class="mt-5 rounded-2xl border border-brand-ink/10 bg-white/80 p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold text-brand-ink">{{ __('Add an address') }}</h2>
        <div class="mt-3 flex flex-wrap items-end gap-3">
            <div class="min-w-56 flex-1">
                <label class="block text-xs font-medium text-brand-moss">{{ __('IP or CIDR') }}</label>
                <input type="text" wire:model="ip" placeholder="203.0.113.4  ·  2600:…  ·  10.0.0.0/24"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm font-mono focus:border-brand-forest focus:ring-brand-forest" />
                @error('ip')<p class="mt-1 text-xs text-brand-rust">{{ $message }}</p>@enderror
            </div>
            <div class="w-44">
                <label class="block text-xs font-medium text-brand-moss">{{ __('Label (optional)') }}</label>
                <input type="text" wire:model="label" placeholder="{{ __('e.g. office') }}"
                    class="mt-1 w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm focus:border-brand-forest focus:ring-brand-forest" />
            </div>
            <button type="button" wire:click="addIp"
                class="rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream hover:bg-brand-forest">
                {{ __('Add') }}
            </button>
        </div>
    </div>

    {{-- Managed list --}}
    <div class="mt-5 rounded-2xl border border-brand-ink/10 bg-white/80 shadow-sm">
        <div class="border-b border-brand-ink/10 px-4 py-3">
            <h2 class="text-sm font-semibold text-brand-ink">{{ __('Allowed addresses') }}</h2>
        </div>
        @forelse ($rows as $row)
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/5 px-4 py-2.5 last:border-0" wire:key="ip-{{ $row->id }}">
                <div class="min-w-0">
                    <code class="font-mono text-sm text-brand-ink">{{ $row->ip }}</code>
                    @if ($row->label)<span class="ml-2 text-xs text-brand-moss">{{ $row->label }}</span>@endif
                </div>
                <button type="button" wire:click="remove({{ $row->id }})"
                    wire:confirm="{{ __('Remove :ip from the allow-list?', ['ip' => $row->ip]) }}"
                    class="shrink-0 rounded-md border border-brand-ink/10 px-2 py-1 text-[11px] font-semibold text-brand-rust hover:bg-brand-rust/5">
                    {{ __('Remove') }}
                </button>
            </div>
        @empty
            <p class="px-4 py-6 text-center text-sm text-brand-moss">{{ __('No managed addresses yet — add one above.') }}</p>
        @endforelse
    </div>

    {{-- Env-provided (read-only) --}}
    @if (! empty($envIps))
        <div class="mt-5 rounded-xl border border-brand-ink/10 bg-brand-sand/10 px-4 py-3">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-brand-mist">{{ __('From COMING_SOON_ALLOWED_IPS (env, read-only)') }}</p>
            <p class="mt-1 font-mono text-xs text-brand-moss">{{ implode('  ·  ', $envIps) }}</p>
        </div>
    @endif
</div>
