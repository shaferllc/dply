{{-- Source control body: one card per provider.
     Replaces three full-width blocks — each card says whether it is connected,
     what is linked, and offers its one action, so a provider you have not
     touched is a small card rather than a header plus an empty-state line. --}}
@include('livewire.settings.partials.source-control._cli-note')

<div class="grid gap-3 p-3 sm:p-4 lg:grid-cols-3">
    @forelse ($providers as $provider)
        @php
            $linked = $provider['accounts']->count() + $provider['pats']->count();
            $unhealthy = $provider['accounts']->filter(fn ($a) => $a->validation_error)->count()
                + $provider['pats']->filter(fn ($p) => $p->validation_error)->count();
        @endphp
        <section @class([
            'flex flex-col overflow-hidden rounded-xl border bg-white shadow-sm',
            'border-rose-200' => $unhealthy > 0,
            'border-brand-ink/12' => $unhealthy === 0,
        ])>
            <div class="flex items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/25 px-3 py-2">
                <x-oauth-provider-icon :provider="$provider['id']" size="h-4 w-4 shrink-0" />
                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-brand-ink">{{ $provider['name'] }}</span>
                <span @class([
                    'shrink-0 rounded-full px-1.5 py-px text-2xs font-semibold',
                    'bg-rose-50 text-rose-700' => $unhealthy > 0,
                    'bg-brand-sage/20 text-brand-forest' => $unhealthy === 0 && $linked > 0,
                    'bg-brand-sand/60 text-brand-mist' => $linked === 0,
                ])>
                    {{ $unhealthy > 0 ? __('needs attention') : ($linked > 0 ? trans_choice(':n linked|:n linked', $linked, ['n' => $linked]) : __('not connected')) }}
                </span>
            </div>

            <div class="flex-1 divide-y divide-brand-ink/8">
                @forelse ($provider['accounts']->concat($provider['pats']) as $item)
                    @php
                        $isPat = $item instanceof \App\Models\GitProviderToken;
                    @endphp
                    <div wire:key="sc2-{{ $isPat ? 'pat' : 'oauth' }}-{{ $item->id }}" class="flex items-center gap-2 px-3 py-1.5">
                        <span @class([
                            'shrink-0 rounded px-1 py-px text-2xs font-semibold uppercase tracking-wide',
                            'bg-violet-50 text-violet-700' => $isPat,
                            'bg-brand-sage/15 text-brand-forest' => ! $isPat,
                        ])>{{ $isPat ? __('PAT') : __('OAuth') }}</span>
                        <span class="min-w-0 flex-1 truncate text-sm text-brand-ink">{{ $item->nickname ?? '—' }}</span>
                        @if ($item->validation_error)
                            <x-heroicon-m-exclamation-triangle class="h-3.5 w-3.5 shrink-0 text-rose-500" aria-hidden="true" title="{{ $item->validation_error }}" />
                        @endif
                        <button
                            type="button"
                            wire:click="openConfirmActionModal('{{ $isPat ? 'unlinkPat' : 'unlinkAccount' }}', ['{{ $item->id }}'], @js($isPat ? __('Remove token') : __('Unlink account')), @js(__('Sites already deployed keep working; new deploys from this identity stop.')), @js($isPat ? __('Remove') : __('Unlink')), true)"
                            class="shrink-0 text-brand-mist transition-colors hover:text-rose-700"
                            title="{{ $isPat ? __('Remove') : __('Unlink') }}"
                        >
                            <x-heroicon-m-x-mark class="h-3.5 w-3.5" aria-hidden="true" />
                        </button>
                    </div>
                @empty
                    <p class="px-3 py-3 text-xs text-brand-mist">{{ __('Nothing linked yet.') }}</p>
                @endforelse
            </div>

            @if ($addingPatProvider === $provider['id'])
                @include('livewire.settings.partials.source-control._pat-form')
            @else
                <div class="flex flex-wrap gap-1.5 border-t border-brand-ink/10 bg-brand-cream/40 px-3 py-2">
                    @if ($provider['oauth_enabled'])
                        <a href="{{ route('oauth.redirect', ['provider' => $provider['id']]) }}" class="inline-flex h-6 items-center gap-1 rounded-md bg-brand-ink px-2 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest">
                            <x-heroicon-o-link class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            {{ $provider['accounts']->isEmpty() ? __('Connect') : __('Add account') }}
                        </a>
                    @endif
                    <button type="button" wire:click="startAddPat('{{ $provider['id'] }}')" class="inline-flex h-6 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40">
                        <x-heroicon-o-key class="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Add token') }}
                    </button>
                </div>
            @endif
        </section>
    @empty
        <p class="px-1 py-6 text-center text-sm text-brand-moss lg:col-span-3">{{ __('No Git providers are configured on this deployment.') }}</p>
    @endforelse
</div>
