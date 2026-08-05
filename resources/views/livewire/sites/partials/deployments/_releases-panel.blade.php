<section class="border-b border-brand-ink/10">
    <x-workspace-panel-head
        class="border-b border-brand-ink/10"
        icon="heroicon-o-archive-box"
        :title="__('Releases & rollback')"
        :count="trans_choice('{0} no releases|{1} :count release|[2,*] :count releases', $site->releases->count(), ['count' => $site->releases->count()])"
        :note="__('Atomic release folders kept on disk. The active release is symlinked into the document root; rolling back swaps the symlink to a previous folder.')"
    />

    @if ($site->releases->isEmpty())
        <p class="px-5 py-8 text-center text-xs text-brand-moss sm:px-6">
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
                            <x-heroicon-o-arrow-uturn-left class="h-4 w-4" aria-hidden="true" />
                            {{ __('Rollback') }}
                        </button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
