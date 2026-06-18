{{-- Project context (feature-gated) --}}
@if ($server->workspace)
    @feature('surface.projects')
        <section class="dply-card overflow-hidden">
            <div class="flex items-start gap-3 px-6 pt-5 pb-4 sm:px-7">
                <x-icon-badge>
                    <x-heroicon-o-rectangle-stack class="h-5 w-5" aria-hidden="true" />
                </x-icon-badge>
                <div class="min-w-0">
                    <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ $server->workspace->name }}</h3>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __('This server is managed as part of the project. Use the project pages when you need access control, grouped activity, shared variables, coordinated deploys, or cross-resource health review.') }}
                    </p>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <a href="{{ route('projects.overview', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-m-eye class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Project overview') }}
                        </a>
                        <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                            <x-heroicon-m-bolt class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Project operations') }}
                        </a>
                    </div>
                </div>
            </div>
        </section>
    @endfeature
@endif
