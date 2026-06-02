{{-- Load balancer target server checkboxes. Expects: $orgServers, wire:model lb_target_server_ids --}}
<div class="space-y-2">
    @foreach ($orgServers as $s)
        <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-brand-ink/10 bg-white px-4 py-3 hover:bg-brand-sand/10">
            <input
                type="checkbox"
                wire:model="lb_target_server_ids"
                value="{{ $s->id }}"
                class="rounded border-brand-ink/30 text-brand-forest focus:ring-brand-sage"
            />
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-brand-ink">{{ $s->name }}</p>
                <p class="font-mono text-[11px] text-brand-mist">
                    {{ $s->private_ip_address ?? $s->ip_address }} · {{ $s->region }}
                    @if ($s->private_ip_address)
                        <span class="text-emerald-600">· {{ __('private') }}</span>
                    @endif
                </p>
            </div>
        </label>
    @endforeach
</div>
