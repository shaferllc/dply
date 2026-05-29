<div>
    <div class="border-b border-brand-ink/10 bg-white">
        <div class="dply-page-shell py-8">
            <x-page-header
                :title="__('Full-stack from one repo')"
                :description="__('Paste a Git repository and dply recommends how to split it across Edge, Cloud, and BYO — then opens each create flow with sensible defaults.')"
                doc-route="docs.index"
                flush
            >
                <x-slot name="actions">
                    <a href="{{ route('launches.create') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">{{ __('Launchpad') }}</a>
                </x-slot>
            </x-page-header>
        </div>
    </div>

    <div class="min-h-[50vh] bg-brand-cream py-10">
        <div class="dply-page-shell max-w-3xl">
            {{-- Step indicator --}}
            <ol class="mb-8 flex flex-wrap gap-2 text-sm font-medium text-brand-moss" aria-label="{{ __('Workflow steps') }}">
                @foreach (['repo' => __('Repository'), 'plan' => __('Architecture'), 'wiring' => __('Wiring')] as $key => $label)
                    <li @class([
                        'rounded-full px-3 py-1 ring-1',
                        'bg-brand-forest text-white ring-brand-forest' => $step === $key,
                        'bg-white text-brand-ink ring-brand-ink/15' => $step !== $key && ($key === 'repo' || ($key === 'plan' && $plan !== []) || ($key === 'wiring' && $step === 'wiring')),
                        'bg-brand-ink/[0.04] text-brand-mist ring-brand-ink/10' => $step !== $key && ! ($key === 'plan' && $plan !== []) && ! ($key === 'wiring' && $step === 'wiring'),
                    ])>{{ $label }}</li>
                @endforeach
            </ol>

            @if ($step === 'repo')
                <section class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm ring-1 ring-brand-ink/[0.04]">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-folder class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Repository') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Start with your repository') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('We shallow-clone the branch, detect runtimes (including monorepos), and map each package to Edge, Cloud, or BYO layers.') }}</p>
                        </div>
                    </div>
                    <div class="px-6 py-6 sm:px-7">
                    <form wire:submit="analyze" class="space-y-4">
                        <div>
                            <x-input-label for="full-stack-repo" :value="__('Git repository URL')" />
                            <x-text-input wire:model="repo" id="full-stack-repo" type="url" class="mt-1 block w-full font-mono text-sm" placeholder="https://github.com/org/monorepo" required />
                            <x-input-error :messages="$errors->get('repo')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="full-stack-branch" :value="__('Branch')" />
                            <x-text-input wire:model="branch" id="full-stack-branch" type="text" class="mt-1 block w-full font-mono text-sm" placeholder="main" required />
                            <x-input-error :messages="$errors->get('branch')" class="mt-2" />
                        </div>
                        <div class="flex flex-wrap items-center gap-3 pt-2">
                            <x-primary-button type="submit" wire:loading.attr="disabled" wire:target="analyze">
                                <span wire:loading.remove wire:target="analyze">{{ __('Analyze repository') }}</span>
                                <span wire:loading wire:target="analyze">{{ __('Cloning and detecting…') }}</span>
                            </x-primary-button>
                        </div>
                    </form>
                    </div>
                </section>
            @endif

            @if ($step === 'plan' && $plan !== [])
                <section class="space-y-6">
                    <div class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm ring-1 ring-brand-ink/[0.04]">
                        <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Architecture') }}</p>
                                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Recommended architecture') }}</h2>
                                <p class="mt-1 font-mono text-sm text-brand-moss">{{ $plan['repo'] ?? '' }} @ {{ $plan['branch'] ?? 'main' }}</p>
                                @if ($plan['is_monorepo'] ?? false)
                                    <p class="mt-2 inline-flex rounded-full bg-brand-sand/60 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-brand-forest">{{ __('Monorepo') }}</p>
                                @endif
                            </div>
                            <button type="button" wire:click="backToRepo" class="shrink-0 text-sm font-medium text-brand-sage hover:text-brand-ink">{{ __('Change repo') }}</button>
                        </div>
                        <div class="px-6 py-6 sm:px-7">

                        @if (! empty($plan['reasons']))
                            <ul class="mt-4 space-y-1 text-sm text-brand-moss">
                                @foreach ($plan['reasons'] as $reason)
                                    <li class="flex gap-2"><span class="text-brand-sage" aria-hidden="true">•</span><span>{{ $reason }}</span></li>
                                @endforeach
                            </ul>
                        @endif

                        @if (! empty($plan['warnings']))
                            <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50/80 px-4 py-3 text-sm text-amber-950">
                                @foreach ($plan['warnings'] as $warning)
                                    <p>{{ $warning }}</p>
                                @endforeach
                            </div>
                        @endif
                        </div>
                    </div>

                    <div class="grid gap-4">
                        @foreach ($plan['layers'] ?? [] as $layer)
                            @php
                                $status = $layer['status'] ?? 'recommended';
                                $badgeClass = match ($status) {
                                    'required' => 'bg-brand-forest/10 text-brand-forest',
                                    'optional' => 'bg-brand-ink/[0.06] text-brand-moss',
                                    default => 'bg-brand-sage/15 text-brand-forest',
                                };
                                $engineIcon = match ($layer['engine'] ?? '') {
                                    'edge' => 'bolt',
                                    'cloud' => 'cloud',
                                    default => 'server',
                                };
                                $launchUrl = $this->launchUrl($layer['launch_route'] ?? 'launches.create', $layer['launch_params'] ?? []);
                            @endphp
                            <article class="rounded-2xl border border-brand-ink/10 bg-white p-5 shadow-sm ring-1 ring-brand-ink/[0.04]">
                                <div class="flex flex-wrap items-start gap-4">
                                    <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-forest ring-1 ring-brand-ink/10">
                                        <x-dynamic-component :component="'heroicon-o-'.$engineIcon" class="h-6 w-6" aria-hidden="true" />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h3 class="text-base font-semibold text-brand-ink">{{ $layer['label'] ?? '' }}</h3>
                                            <span @class(['rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide', $badgeClass])>{{ $status }}</span>
                                            @if (! empty($layer['framework']))
                                                <span class="text-xs font-medium text-brand-moss">{{ $layer['framework'] }}</span>
                                            @endif
                                        </div>
                                        <p class="mt-2 text-sm leading-6 text-brand-moss">{{ $layer['description'] ?? '' }}</p>
                                        @if (! empty($layer['repo_root']))
                                            <p class="mt-2 font-mono text-xs text-brand-mist">{{ __('Package') }}: {{ $layer['repo_root'] }}</p>
                                        @endif
                                    </div>
                                    <a href="{{ $launchUrl }}" wire:navigate class="inline-flex shrink-0 items-center justify-center rounded-xl bg-brand-forest px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-brand-ink">
                                        {{ __('Open create flow') }} →
                                    </a>
                                </div>
                            </article>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <x-primary-button type="button" wire:click="showWiring">{{ __('View wiring guide') }}</x-primary-button>
                        <a href="{{ route('infrastructure.index') }}" wire:navigate class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Infrastructure hub') }}</a>
                    </div>
                </section>
            @endif

            @if ($step === 'wiring' && $plan !== [])
                <section class="overflow-hidden rounded-2xl border border-brand-ink/10 bg-white shadow-sm ring-1 ring-brand-ink/[0.04]">
                    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-wrench-screwdriver class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Wiring') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Wiring guide') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">{{ __('Follow these steps after each layer is provisioned. Order matters when origins and databases must exist before the edge front goes live.') }}</p>
                        </div>
                    </div>
                    <div class="px-6 py-6 sm:px-7">
                    <ol class="list-decimal space-y-3 ps-5 text-sm leading-6 text-brand-ink">
                        @foreach ($plan['wiring_hints'] ?? [] as $hint)
                            <li>{{ $hint }}</li>
                        @endforeach
                    </ol>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <button type="button" wire:click="$set('step', 'plan')" class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">{{ __('Back to architecture') }}</button>
                    </div>
                    </div>
                </section>
            @endif
        </div>
    </div>
</div>
