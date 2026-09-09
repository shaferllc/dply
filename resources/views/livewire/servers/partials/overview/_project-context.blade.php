{{-- Project context (feature-gated).

     Was a 10x10 icon badge over a three-line paragraph explaining what projects
     are, on a page you reach *from* the project. Trimmed to the one fact that
     changes what you do — this server belongs to a project — with the two links
     inline instead of stacked below. --}}
@if ($server->workspace)
    @feature('surface.projects')
        <section class="dply-card overflow-hidden p-0">
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-5 py-3 sm:px-6">
                <div class="flex min-w-0 flex-1 basis-72 items-center gap-2">
                    <x-heroicon-o-rectangle-stack class="h-4 w-4 shrink-0 text-brand-sage" aria-hidden="true" />
                    <p class="min-w-0 truncate text-sm text-brand-moss">
                        <span class="font-semibold text-brand-ink">{{ $server->workspace->name }}</span>
                        · {{ __('access control, shared variables, and coordinated deploys live on the project pages.') }}
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <a href="{{ route('projects.overview', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        <x-heroicon-m-eye class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                        {{ __('Overview') }}
                    </a>
                    <a href="{{ route('projects.operations', $server->workspace) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        <x-heroicon-m-bolt class="h-3.5 w-3.5 shrink-0 opacity-70" aria-hidden="true" />
                        {{ __('Operations') }}
                    </a>
                </div>
            </div>
        </section>
    @endfeature
@endif
