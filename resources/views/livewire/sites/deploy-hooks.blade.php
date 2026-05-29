@php
    $phaseHints = [
        \App\Models\SiteDeployHook::PHASE_BEFORE_CLONE => __('Runs after checkout, before the build command.'),
        \App\Models\SiteDeployHook::PHASE_AFTER_CLONE => __('Runs after dependencies install, before packaging.'),
        \App\Models\SiteDeployHook::PHASE_AFTER_ACTIVATE => __('Runs once the function is live.'),
    ];
@endphp
<div class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-rocket-launch class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Deploy hooks') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Custom build steps') }}</h2>
            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Shell that runs during this function\'s deploy — e.g. compile assets, warm caches, notify a service. A non-zero exit aborts the deploy.') }}</p>
        </div>
        <button type="button" wire:click="$toggle('formOpen')"
                class="ml-auto shrink-0 inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:border-brand-sage/40">
            {{ $formOpen ? __('Cancel') : __('Add hook') }}
        </button>
    </div>

    <div class="px-6 py-6 sm:px-7 space-y-5">
    @if ($formOpen)
        <form wire:submit="addHook" class="space-y-3 rounded-xl border border-brand-ink/10 bg-brand-sand/20 p-4">
            <div class="flex flex-wrap gap-3">
                <label class="text-xs text-brand-moss">
                    <span class="block font-semibold">{{ __('Phase') }}</span>
                    <select wire:model="newPhase" class="mt-1 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-xs">
                        @foreach ($phaseLabels as $phase => $label)
                            <option value="{{ $phase }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="text-xs text-brand-moss">
                    <span class="block font-semibold">{{ __('Order') }}</span>
                    <input type="number" wire:model="newOrder" min="0" max="999"
                           class="mt-1 w-20 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-xs">
                </label>
                <label class="text-xs text-brand-moss">
                    <span class="block font-semibold">{{ __('Timeout (s)') }}</span>
                    <input type="number" wire:model="newTimeout" min="30" max="3600"
                           class="mt-1 w-24 rounded-lg border border-brand-ink/15 bg-white px-2 py-1.5 text-xs">
                </label>
            </div>
            <div>
                <textarea wire:model="newScript" rows="5" placeholder="npm ci && npm run build"
                          class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 font-mono text-xs"></textarea>
                <x-input-error :messages="$errors->get('newScript')" class="mt-1" />
                <x-input-error :messages="$errors->get('newTimeout')" class="mt-1" />
            </div>
            <button type="submit"
                    class="inline-flex items-center rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-forest/90">
                {{ __('Add hook') }}
            </button>
        </form>
    @endif

    <div class="space-y-4">
        @foreach ($phaseLabels as $phase => $label)
            @php $hooks = $hooksByPhase[$phase] ?? collect(); @endphp
            <div>
                <div class="flex items-baseline gap-2">
                    <h3 class="text-sm font-semibold text-brand-ink">{{ $label }}</h3>
                    <span class="text-[11px] text-brand-moss/60">{{ $phaseHints[$phase] ?? '' }}</span>
                </div>
                @if ($hooks->isEmpty())
                    <p class="mt-1 text-xs text-brand-moss/60">{{ __('No hooks in this phase.') }}</p>
                @else
                    <ul class="mt-2 space-y-2">
                        @foreach ($hooks as $hook)
                            <li class="rounded-xl border border-brand-ink/10 bg-white p-3">
                                <div class="flex items-center justify-between gap-2 text-[11px] text-brand-moss">
                                    <span class="font-semibold text-brand-ink">#{{ $hook->sort_order }}
                                        <span class="font-normal text-brand-moss">· {{ (int) ($hook->timeout_seconds ?? 900) }}s {{ __('timeout') }}</span>
                                    </span>
                                    <button type="button" wire:click="deleteHook({{ $hook->id }})"
                                            wire:confirm="{{ __('Remove this deploy hook?') }}"
                                            class="font-semibold text-rose-700 hover:underline">{{ __('Remove') }}</button>
                                </div>
                                <pre class="mt-2 overflow-x-auto rounded-lg bg-brand-ink p-3 text-[11px] leading-relaxed text-brand-cream">{{ \Illuminate\Support\Str::limit($hook->script, 800) }}</pre>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
    </div>
</div>
