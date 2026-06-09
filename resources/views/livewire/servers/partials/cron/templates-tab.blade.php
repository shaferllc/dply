{{--
    Cron Templates tab content.

    Two sections: built-in bundles (one-click starters, read by everyone) and
    organization templates (custom recipes; admins can create/delete, deployers
    can apply).

    Required vars: $bundledCronJobs, $cronJobCount, $card, $orgCronTemplates, $canUpdateOrg.
--}}

@if (! empty($bundledCronJobs))
    <div class="{{ $card }}">
        <div class="flex min-w-0 items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
            <x-icon-badge>
                <x-heroicon-o-sparkles class="h-5 w-5" aria-hidden="true" />
            </x-icon-badge>
            <div class="min-w-0">
                <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Templates') }}</p>
                <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Built-in bundles') }}</h2>
                <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                    {{ __('One-click starters for things people normally schedule. Each adds rows to the Jobs tab — review and edit (paths/domains), then sync the crontab.') }}
                </p>
            </div>
        </div>
        <div class="grid gap-3 px-6 py-5 sm:grid-cols-2 sm:px-8 lg:grid-cols-3">
            @foreach ($bundledCronJobs as $bundleKey => $bundle)
                <div class="flex flex-col gap-3 rounded-xl border border-brand-ink/10 bg-white p-4 shadow-sm">
                    <div class="flex min-w-0 items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-brand-ink">{{ $bundle['label'] }}</p>
                            <p class="mt-0.5 text-[11px] text-brand-mist">
                                {{ trans_choice('{1} :count entry|[2,*] :count entries', $bundle['entry_count'], ['count' => $bundle['entry_count']]) }}
                            </p>
                        </div>
                        @if ($bundle['applied'])
                            <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700 ring-1 ring-emerald-200">
                                <x-heroicon-o-check-circle class="h-3 w-3" />
                                {{ __('Added') }}
                            </span>
                        @endif
                    </div>
                    <p class="text-xs leading-relaxed text-brand-moss">{{ $bundle['description'] }}</p>
                    <div class="mt-auto">
                        <button
                            type="button"
                            wire:click="applyCronBundle('{{ $bundleKey }}')"
                            wire:loading.attr="disabled"
                            wire:target="applyCronBundle('{{ $bundleKey }}')"
                            @class([
                                'inline-flex w-full items-center justify-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold shadow-sm transition-colors disabled:cursor-not-allowed disabled:opacity-50',
                                'border border-brand-ink/15 bg-white text-brand-ink hover:bg-brand-sand/40' => $bundle['applied'],
                                'bg-brand-forest text-brand-cream hover:bg-brand-forest/90' => ! $bundle['applied'],
                            ])
                        >
                            <span wire:loading.remove wire:target="applyCronBundle('{{ $bundleKey }}')" class="inline-flex items-center gap-1.5">
                                @if ($bundle['applied'])
                                    <x-heroicon-o-arrow-path class="h-4 w-4" />
                                    {{ __('Add again') }}
                                @else
                                    <x-heroicon-o-plus class="h-4 w-4" />
                                    {{ __('Add to panel') }}
                                @endif
                            </span>
                            <span wire:loading wire:target="applyCronBundle('{{ $bundleKey }}')" class="inline-flex items-center gap-1.5">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Adding…') }}
                            </span>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif

<div class="{{ $card }}">
    <div class="flex min-w-0 items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-8">
        <x-icon-badge>
            <x-heroicon-o-document-duplicate class="h-5 w-5" aria-hidden="true" />
        </x-icon-badge>
        <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Templates') }}</p>
            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Organization templates') }}</h2>
            <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                {{ __('Reusable cron recipes saved for your team. Click Apply to load one into the Add Cron Job form on the Jobs tab — review and save to install it on this server.') }}
            </p>
        </div>
    </div>

    @if ($orgCronTemplates->isEmpty())
        <p class="px-6 py-10 text-center text-sm text-brand-moss sm:px-8">
            @if ($canUpdateOrg)
                {{ __('No organization templates yet. Open “Add cron job”, fill the form, and use “Save as org template” at the bottom to store one for your team.') }}
            @else
                {{ __('No organization templates yet. Ask an organization owner to save reusable recipes here.') }}
            @endif
        </p>
    @else
        <ul class="divide-y divide-brand-ink/10">
            @foreach ($orgCronTemplates as $tpl)
                <li class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-start sm:justify-between sm:gap-6 sm:px-8">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <h4 class="truncate text-sm font-semibold text-brand-ink">{{ $tpl->name }}</h4>
                            <span class="inline-flex items-center gap-1 rounded-md bg-brand-sand/50 px-1.5 py-0.5 font-mono text-[11px] text-brand-ink/80 ring-1 ring-brand-ink/10">
                                <x-heroicon-m-clock class="h-3 w-3 text-brand-moss" />
                                {{ $tpl->cron_expression }}
                            </span>
                            <span class="inline-flex items-center gap-1 rounded-md bg-white px-1.5 py-0.5 text-[11px] text-brand-ink/80 ring-1 ring-brand-ink/10">
                                <x-heroicon-m-user class="h-3 w-3 text-brand-moss" />
                                {{ $tpl->user }}
                            </span>
                        </div>
                        @if (filled($tpl->description))
                            <p class="mt-1 text-xs text-brand-moss">{{ $tpl->description }}</p>
                        @endif
                        <p class="mt-1 break-all font-mono text-xs text-brand-ink/70">{{ $tpl->command }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            type="button"
                            wire:click="applyOrgCronTemplate('{{ $tpl->id }}')"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest/90"
                        >
                            <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                            {{ __('Apply') }}
                        </button>
                        @if ($canUpdateOrg)
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('deleteOrgCronTemplate', ['{{ $tpl->id }}'], @js(__('Delete template')), @js(__('Delete this organization template? Existing jobs created from it are unaffected.')), @js(__('Delete template')), true)"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-rose-700 shadow-sm hover:bg-rose-50"
                            >
                                <x-heroicon-o-trash class="h-4 w-4" />
                                {{ __('Delete') }}
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
