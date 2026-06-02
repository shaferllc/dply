<section class="dply-card overflow-hidden">
    <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
            <x-heroicon-o-archive-box class="h-5 w-5" aria-hidden="true" />
        </span>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Releases') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Releases & rollback') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Atomic release folders kept on disk. The active release is symlinked into the document root; rolling back swaps the symlink to a previous folder.') }}</p>
        </div>
        <span class="ml-auto shrink-0 self-center text-xs text-brand-mist">
            {{ trans_choice('{0} no releases|{1} :count release|[2,*] :count releases', $site->releases->count(), ['count' => $site->releases->count()]) }}
        </span>
    </div>

    @if ($site->releases->isEmpty())
        <p class="px-6 py-12 text-center text-sm text-brand-moss sm:px-8">
            {{ __('No releases on disk yet. Run a deploy with the atomic strategy and it will appear here.') }}
        </p>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($site->releases as $rel)
                <li class="flex items-center justify-between gap-3 px-6 py-4 transition-colors hover:bg-brand-sand/15 sm:px-8">
                    <div class="min-w-0">
                        <p class="flex items-center gap-2 font-mono text-xs text-brand-ink">
                            {{ $rel->folder }}
                            @if ($rel->is_active)
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-800 ring-1 ring-inset ring-emerald-200">{{ __('Active') }}</span>
                            @endif
                        </p>
                        @if ($rel->git_sha)
                            <p class="mt-1 font-mono text-[11px] text-brand-mist">{{ $rel->git_sha }}</p>
                        @endif
                    </div>
                    @if (! $rel->is_active)
                        <button type="button"
                                wire:click="confirmRollbackRelease('{{ $rel->id }}')"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                            <x-heroicon-o-arrow-uturn-left class="h-3.5 w-3.5" aria-hidden="true" />
                            {{ __('Rollback') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
