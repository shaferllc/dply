@php
    $phaseHints = [
        \App\Models\SiteDeployHook::PHASE_BEFORE_CLONE => __('Runs after checkout, before the build command.'),
        \App\Models\SiteDeployHook::PHASE_AFTER_CLONE => __('Runs after dependencies install, before packaging.'),
        \App\Models\SiteDeployHook::PHASE_AFTER_ACTIVATE => __('Runs once the function is live.'),
    ];
    $pad = 'px-3 py-2.5 sm:px-4';
@endphp
{{-- Hairline strip (not a nested card) — sits inside Deployments merged chrome. --}}
<div class="border-t border-brand-ink/10">
    <div class="flex flex-wrap items-start justify-between gap-2 border-b border-brand-ink/10 bg-brand-sand/20 {{ $pad }}">
        <div class="min-w-0 flex-1">
            <div class="flex items-center gap-1.5">
                <x-heroicon-o-rocket-launch class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                <h2 class="text-sm font-semibold text-brand-ink">{{ __('Deploy hooks') }}</h2>
            </div>
            <p class="mt-0.5 text-[11px] leading-snug text-brand-moss">{{ __('Shell during this function\'s deploy — e.g. compile assets. A non-zero exit aborts the deploy.') }}</p>
        </div>
        <button type="button" wire:click="$toggle('formOpen')"
                class="inline-flex shrink-0 items-center rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">
            {{ $formOpen ? __('Cancel') : __('Add hook') }}
        </button>
    </div>

    @if ($formOpen)
        <form wire:submit="addHook" class="space-y-2 border-b border-brand-ink/10 bg-brand-sand/10 {{ $pad }}">
            <div class="flex flex-wrap gap-2">
                <label class="text-[11px] text-brand-moss">
                    <span class="block font-semibold">{{ __('Phase') }}</span>
                    <select wire:model="newPhase" class="mt-0.5 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs">
                        @foreach ($phaseLabels as $phase => $label)
                            <option value="{{ $phase }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-[11px] text-brand-moss">
                    <span class="block font-semibold">{{ __('Order') }}</span>
                    <input type="number" wire:model="newOrder" min="0" max="999"
                           class="mt-0.5 w-16 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs">
                </label>
                <label class="text-[11px] text-brand-moss">
                    <span class="block font-semibold">{{ __('Timeout (s)') }}</span>
                    <input type="number" wire:model="newTimeout" min="30" max="3600"
                           class="mt-0.5 w-20 rounded-lg border border-brand-ink/15 bg-white px-2 py-1 text-xs">
                </label>
            </div>
            <div>
                <textarea wire:model="newScript" rows="4" placeholder="npm ci && npm run build"
                          class="w-full rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 font-mono text-xs"></textarea>
                <x-input-error :messages="$errors->get('newScript')" class="mt-1" />
                <x-input-error :messages="$errors->get('newTimeout')" class="mt-1" />
            </div>
            <button type="submit"
                    class="inline-flex items-center rounded-lg bg-brand-forest px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-forest/90">
                {{ __('Add hook') }}
            </button>
        </form>
    @endif

    <div class="divide-y divide-brand-ink/10">
        @foreach ($phaseLabels as $phase => $label)
            @php $hooks = $hooksByPhase[$phase] ?? collect(); @endphp
            <div class="{{ $pad }}">
                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <h3 class="text-xs font-semibold text-brand-ink">{{ $label }}</h3>
                    <span class="text-[11px] text-brand-moss/60">{{ $phaseHints[$phase] ?? '' }}</span>
                </div>
                @if ($hooks->isEmpty())
                    <p class="mt-1 text-[11px] text-brand-moss/60">{{ __('No hooks in this phase.') }}</p>
                @else
                    <ul class="mt-1.5 space-y-1.5">
                        @foreach ($hooks as $hook)
                            <li class="rounded-lg border border-brand-ink/10 bg-white px-2.5 py-2" wire:key="deploy-hook-{{ $hook->id }}">
                                <div class="flex items-center justify-between gap-2 text-[11px] text-brand-moss">
                                    <span class="font-semibold text-brand-ink">#{{ $hook->sort_order }}
                                        <span class="font-normal text-brand-moss">· {{ (int) ($hook->timeout_seconds ?? 900) }}s {{ __('timeout') }}</span>
                                    </span>
                                    <button type="button" wire:click="confirmDeleteHook('{{ $hook->id }}')"
                                            class="font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                </div>
                                <pre class="mt-1.5 overflow-x-auto rounded-md bg-brand-ink p-2 text-[11px] leading-relaxed text-brand-cream">{{ \Illuminate\Support\Str::limit($hook->script, 800) }}</pre>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</div>

@include('livewire.partials.confirm-action-modal')
