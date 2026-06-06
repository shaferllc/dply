<section class="space-y-6">
    {{-- No overflow-hidden here: the Deploy-ref picker is an absolutely-positioned
         dropdown that must escape the card bounds. Header/footer round their own
         corners instead so the card still looks clipped. --}}
    <div class="dply-card">
        <div class="flex items-start gap-3 rounded-t-2xl border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                <x-heroicon-o-link class="h-5 w-5" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Source control') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Connection') }}</h2>
                <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                    {{ __('Pick the account and repository dply deploys from, then choose the ref. Reads (commits, branches, files) use the account you select here.') }}
                </p>
            </div>
        </div>

        <form wire:submit.prevent="saveConnection" class="space-y-4 px-6 py-6 sm:px-7">
            {{-- Rich picker: source toggle + account select + searchable repository
                 dropdown + manual URL (shared with choose-app / create-custom). --}}
            @include('livewire.sites.partials._git-repository-configurator', ['idPrefix' => 'conn'])

            <div class="relative block text-sm">
                <span class="block text-xs font-semibold uppercase tracking-[0.12em] text-brand-moss">{{ __('Deploy ref') }}</span>
                <div class="mt-1 flex flex-wrap items-center gap-2">
                    <span @class([
                        'inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 font-mono text-sm',
                        'border-violet-200 bg-violet-50 text-violet-900' => ($git_ref_kind ?? 'branch') === 'branch',
                        'border-amber-200 bg-amber-50 text-amber-900' => $git_ref_kind === 'tag',
                        'border-sky-200 bg-sky-50 text-sky-900' => $git_ref_kind === 'commit',
                    ])>
                        <span class="text-[10px] font-semibold uppercase tracking-wide">{{ match ($git_ref_kind ?? 'branch') {
                            'tag' => __('Tag'),
                            'commit' => __('Commit'),
                            default => __('Branch'),
                        } }}</span>
                        <span>{{ $git_ref_kind === 'commit'
                            ? \Illuminate\Support\Str::limit($git_branch ?: 'main', 12, '')
                            : ($git_branch ?: 'main') }}</span>
                    </span>
                    <button type="button" wire:click="openConnectionRefPicker"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                        <x-heroicon-o-arrows-right-left class="h-3.5 w-3.5" aria-hidden="true" />
                        {{ __('Change…') }}
                    </button>
                </div>
                <p class="mt-1 text-xs text-brand-moss">{{ __('Pick a branch, tag, or specific commit. Saved when you click “Save connection”.') }}</p>

                @if ($repo_ref_picker_open)
                    {{-- Anchored dropdown: floats over the form instead of pushing
                         the layout. The picker partial closes on outside-click. --}}
                    <div class="absolute left-0 top-full z-30 w-[min(28rem,90vw)]">
                        @include('livewire.sites.partials._repository-ref-picker')
                    </div>
                @endif
            </div>
        </form>

        <div class="flex justify-end rounded-b-2xl border-t border-brand-ink/10 bg-brand-sand/25 px-6 py-4 sm:px-7">
            <button
                type="button"
                wire:click="saveConnection"
                wire:loading.attr="disabled"
                wire:target="saveConnection"
                class="inline-flex items-center gap-2 rounded-lg bg-brand-ink px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
            >
                <x-heroicon-o-check class="h-4 w-4" />
                <span wire:loading.remove wire:target="saveConnection">{{ __('Save connection') }}</span>
                <span wire:loading wire:target="saveConnection">{{ __('Saving…') }}</span>
            </button>
        </div>
    </div>

    {{-- The "Repositories on this account" library card + its swap-confirmation
         modal were removed: the picker above lists the account's repositories
         inline, so switching is just picking a different repo and saving. --}}

    {{-- Quick deploy webhook lives as its own card in Deployments → Settings
         (rendered by repository/partials/webhook.blade.php via lockedTab="webhook").
         "Disconnect repository & start over" lives on the Danger tab. --}}
</section>
