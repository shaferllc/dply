{{-- Ability picker, shared by the create-token and edit-scopes modals: both
     write to $selected_abilities, so the markup only needs to exist once. --}}
<div>
    <div class="flex items-baseline justify-between gap-3">
        <p class="text-sm font-semibold text-brand-ink">{{ __('Permissions') }}</p>
        <button
            type="button"
            wire:click="toggleAllPermissions"
            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40"
        >
            <x-heroicon-o-arrows-right-left class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ __('Toggle all') }}
        </button>
    </div>
    @if (count($selected_abilities) === 0)
        <div class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900">
            <span class="inline-flex items-center gap-1.5 font-semibold">
                <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                {{ __('No permissions selected') }}
            </span>
            <p class="mt-1 leading-relaxed">{{ __('Pick at least one permission so the token can do something with the API.') }}</p>
        </div>
    @endif
    <x-input-error :messages="$errors->get('selected_abilities')" class="mt-2" />

    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        @foreach ($permissionCategories as $cat)
            @php
                $catId = $cat['id'];
                $perms = $cat['permissions'] ?? [];
                $abilityList = collect($perms)->pluck('ability')->all();
                $selectedInCat = count(array_intersect($selected_abilities, $abilityList));
                $totalInCat = count($abilityList);
                $expanded = in_array($catId, $expanded_categories, true);
                $allSelected = $totalInCat > 0 && $selectedInCat === $totalInCat;
            @endphp
            <div class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white">
                <button
                    type="button"
                    wire:click="toggleCategoryExpand('{{ $catId }}')"
                    @class([
                        'flex w-full items-center justify-between gap-2 px-3 py-2.5 text-left text-sm font-semibold transition',
                        'bg-brand-sage/8 text-brand-forest' => $allSelected,
                        'bg-brand-cream/40 text-brand-ink hover:bg-brand-sand/40' => ! $allSelected,
                    ])
                    aria-expanded="{{ $expanded ? 'true' : 'false' }}"
                >
                    <span>{{ $cat['label'] }}</span>
                    <span class="inline-flex items-center gap-1.5 text-xs font-medium text-brand-moss">
                        <span @class([
                            'rounded-full px-1.5 py-0.5 tabular-nums',
                            'bg-brand-sage/20 text-brand-forest' => $selectedInCat > 0,
                            'bg-brand-sand/60 text-brand-mist' => $selectedInCat === 0,
                        ])>{{ $selectedInCat }}/{{ $totalInCat }}</span>
                        <x-heroicon-m-chevron-down class="h-4 w-4 transition-transform {{ $expanded ? 'rotate-180' : '' }}" aria-hidden="true" />
                    </span>
                </button>
                @if ($expanded)
                    <div class="space-y-1.5 border-t border-brand-ink/10 bg-white px-3 py-3">
                        @foreach ($perms as $p)
                            @php $ab = $p['ability']; @endphp
                            <label class="flex cursor-pointer items-center gap-2 rounded-lg px-1.5 py-1 text-sm text-brand-moss hover:bg-brand-sand/30">
                                <input
                                    type="checkbox"
                                    wire:click.prevent="toggleAbility('{{ $ab }}')"
                                    @checked(in_array($ab, $selected_abilities, true))
                                    class="h-4 w-4 rounded border-brand-ink/30 text-brand-forest focus:ring-brand-forest"
                                />
                                <span class="text-brand-ink">{{ $p['label'] }}</span>
                            </label>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
