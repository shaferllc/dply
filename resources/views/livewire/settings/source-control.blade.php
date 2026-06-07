@php
    $tonePalette = [
        'sage' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25',
        'sky' => 'bg-sky-50 text-sky-700 ring-sky-200',
        'amber' => 'bg-amber-50 text-amber-900 ring-amber-200',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200',
        'sand' => 'bg-brand-sand/55 text-brand-forest ring-brand-ink/10',
    ];

    $providers = $this->providers;
    $totalOAuth = collect($providers)->sum(fn ($p) => $p['accounts']->count());
    $totalPats = collect($providers)->sum(fn ($p) => $p['pats']->count());
    $providersWithAny = collect($providers)
        ->filter(fn ($p) => $p['accounts']->isNotEmpty() || $p['pats']->isNotEmpty())
        ->count();
@endphp

<div>
    <x-livewire-validation-errors />

    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
        ['label' => __('Source control'), 'icon' => 'code-bracket-square'],
    ]" />

    {{-- Hero: positioning + at-a-glance link counts. --}}
    <section class="dply-card overflow-hidden">
        <div class="grid gap-6 p-6 sm:p-8 lg:grid-cols-12 lg:items-center lg:gap-8">
            <div class="lg:col-span-7">
                <div class="flex items-start gap-3">
                    <x-icon-badge size="md">
                        <x-heroicon-o-code-bracket-square class="h-6 w-6" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Git') }}</p>
                        <h2 class="mt-1 text-xl font-semibold tracking-tight text-brand-ink">{{ __('Source control') }}</h2>
                        <p class="mt-2 max-w-xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Link GitHub, GitLab, or Bitbucket via OAuth, or paste a personal access token. Tokens unlock clone, browse, and webhook automation — and let you connect self-hosted hosts that OAuth can\'t cover.') }}
                        </p>
                    </div>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-2">
                    <x-outline-link href="{{ route('settings.profile') }}" wire:navigate>
                        <x-heroicon-o-user-circle class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Back to profile') }}
                    </x-outline-link>
                    <x-docs-link doc-route="docs.markdown" doc-slug="source-control">
                        <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                        {{ __('Source control docs') }}
                    </x-docs-link>
                    @if (auth()->user()->currentOrganization())
                        <x-outline-link href="{{ route('credentials.index') }}" wire:navigate>
                            <x-heroicon-o-key class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Server providers') }}
                        </x-outline-link>
                    @endif
                </div>
            </div>
            <dl class="grid grid-cols-3 gap-2 lg:col-span-5">
                <div class="rounded-2xl border border-brand-ink/10 bg-white px-4 py-3 shadow-sm">
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Hosts') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $providersWithAny }}</span>
                        <span class="text-[11px] text-brand-moss">/ {{ count($providers) }} {{ __('linked') }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('GitHub, GitLab, Bitbucket') }}</p>
                </div>
                <div @class([
                    'rounded-2xl border px-4 py-3 shadow-sm',
                    'border-brand-sage/30 bg-brand-sage/8' => $totalOAuth > 0,
                    'border-brand-ink/10 bg-white' => $totalOAuth === 0,
                ])>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('OAuth') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $totalOAuth }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('account|accounts', $totalOAuth) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Browser sign-in flow') }}</p>
                </div>
                <div @class([
                    'rounded-2xl border px-4 py-3 shadow-sm',
                    'border-violet-200 bg-violet-50' => $totalPats > 0,
                    'border-brand-ink/10 bg-white' => $totalPats === 0,
                ])>
                    <dt class="text-[10px] font-semibold uppercase tracking-wide text-brand-mist">{{ __('Tokens') }}</dt>
                    <dd class="mt-1 flex items-baseline gap-1.5">
                        <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $totalPats }}</span>
                        <span class="text-[11px] text-brand-moss">{{ trans_choice('PAT|PATs', $totalPats) }}</span>
                    </dd>
                    <p class="mt-1 text-[11px] text-brand-mist">{{ __('Self-hosted + machine users') }}</p>
                </div>
            </dl>
        </div>
    </section>

    @error('unlink')
        <div class="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <span class="inline-flex items-center gap-1.5 font-semibold">
                <x-heroicon-m-exclamation-triangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ $message }}
            </span>
        </div>
    @enderror

    <div class="mt-6 space-y-6">
        @forelse ($providers as $provider)
            @php
                $count = $this->repositoryCount($provider['host']);
                $hasAny = $provider['accounts']->isNotEmpty() || $provider['pats']->isNotEmpty();
                $providerLinkedCount = $provider['accounts']->count() + $provider['pats']->count();
            @endphp

            <section class="dply-card overflow-hidden" aria-labelledby="sc-heading-{{ $provider['id'] }}">
                {{-- Provider header strip. Brand icon stays in a sand tile
                     for visual consistency with the rest of the family,
                     while OAuth/PAT counts show as a quick-read chip. --}}
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <x-icon-badge>
                        <x-oauth-provider-icon :provider="$provider['id']" size="h-5 w-5" />
                    </x-icon-badge>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Provider') }}</p>
                        <h3 id="sc-heading-{{ $provider['id'] }}" class="mt-0.5 text-base font-semibold text-brand-ink">{{ $provider['name'] }}</h3>
                        <p class="mt-1 text-sm leading-relaxed text-brand-moss">{{ __('Link an OAuth account or paste a personal access token for self-hosted hosts and machine-user workflows.') }}</p>
                    </div>
                    @if ($hasAny)
                        <span class="shrink-0 inline-flex items-center gap-1.5 rounded-full bg-brand-sage/15 px-2.5 py-0.5 text-[11px] font-semibold tabular-nums text-brand-forest ring-1 ring-brand-sage/20">
                            <x-heroicon-m-check-circle class="h-3 w-3" aria-hidden="true" />
                            {{ $providerLinkedCount }}
                        </span>
                    @endif
                </div>

                {{-- Action row: OAuth link button + PAT add button. --}}
                <div class="flex flex-wrap items-center justify-end gap-2 border-b border-brand-ink/10 bg-brand-sand/25 px-6 py-3 sm:px-7">
                    @if ($provider['oauth_enabled'])
                        <a
                            href="{{ route('oauth.redirect', ['provider' => $provider['id']]) }}"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Link :name', ['name' => $provider['name']]) }}
                        </a>
                    @endif
                    <button
                        type="button"
                        wire:click="startAddPat('{{ $provider['id'] }}')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Add personal access token') }}
                    </button>
                </div>

                {{-- PAT inline editor. --}}
                @if ($addingPatProvider === $provider['id'])
                    <div class="space-y-4 border-b border-brand-ink/10 bg-brand-sage/5 p-6 sm:p-7">
                        <div>
                            <p class="text-sm font-semibold text-brand-ink">{{ __('Add a :name personal access token', ['name' => $provider['name']]) }}</p>
                            <p class="mt-1 text-[11px] leading-relaxed text-brand-moss">
                                @if ($provider['id'] === 'github')
                                    {{ __('Classic PATs need repo and admin:repo_hook scopes. Fine-grained tokens need Contents (Read), Metadata (Read), and Webhooks (Read & Write) for the target repositories.') }}
                                @elseif ($provider['id'] === 'gitlab')
                                    {{ __('Token needs the api scope. Group-scoped tokens cover every project under that group.') }}
                                @else
                                    {{ __('App password or workspace access token with repository:read and webhook permissions.') }}
                                @endif
                            </p>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <x-input-label for="pat-label-{{ $provider['id'] }}" :value="__('Label (optional)')" />
                                <x-text-input id="pat-label-{{ $provider['id'] }}" wire:model="patLabel" class="mt-1 block w-full" placeholder="{{ __('e.g. machine user, work account') }}" />
                                @error('patLabel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <x-input-label for="pat-token-{{ $provider['id'] }}" :value="__('Token')" />
                                <x-text-input id="pat-token-{{ $provider['id'] }}" type="password" wire:model="patToken" class="mt-1 block w-full font-mono" autocomplete="off" />
                                @error('patToken') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @if ($provider['id'] !== 'bitbucket')
                            <div>
                                <x-input-label for="pat-base-{{ $provider['id'] }}" :value="$provider['id'] === 'github' ? __('API base URL (optional, for GitHub Enterprise)') : __('API base URL (optional, for self-hosted GitLab)')" />
                                <x-text-input
                                    id="pat-base-{{ $provider['id'] }}"
                                    wire:model="patApiBaseUrl"
                                    class="mt-1 block w-full font-mono"
                                    placeholder="{{ $provider['id'] === 'github' ? 'https://github.example.com/api/v3' : 'https://gitlab.example.com' }}"
                                />
                                @error('patApiBaseUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                <p class="mt-1 text-[11px] text-brand-mist">{{ __('Leave blank for the public :host host.', ['host' => $provider['host']]) }}</p>
                            </div>
                        @endif

                        <div class="flex flex-wrap justify-end gap-2 border-t border-brand-ink/10 pt-3">
                            <button type="button" wire:click="cancelAddPat" class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                            <button type="button" wire:click="savePat" class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm hover:bg-brand-forest">
                                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Validate and save') }}
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Linked accounts + tokens list. Each item is a row in
                     the family card style. --}}
                @if (! $hasAny)
                    <div class="px-6 py-10 text-center sm:px-7">
                        <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-oauth-provider-icon :provider="$provider['id']" size="h-5 w-5" />
                        </span>
                        <p class="mt-3 text-sm text-brand-moss">{{ __('No linked accounts or tokens yet.') }}</p>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($provider['accounts'] as $account)
                            <li wire:key="sc-oauth-{{ $account->id }}" class="flex flex-col gap-3 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-7">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                        <span class="inline-flex items-center rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-brand-forest">{{ __('OAuth') }}</span>
                                        <span class="truncate text-sm font-semibold text-brand-ink">{{ $account->nickname ?? '—' }}</span>
                                        @if ($editingId === (string) $account->id)
                                            <x-text-input wire:model="editLabel" class="block w-full max-w-xs text-xs" placeholder="{{ __('Label (optional)') }}" />
                                        @elseif ($account->label)
                                            <span class="inline-flex items-center rounded-md bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-medium text-brand-moss">{{ $account->label }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-mist">
                                        <span>{{ __('Added :time', ['time' => $account->created_at?->diffForHumans() ?? '—']) }}</span>
                                        @if ($count > 0)
                                            <span class="text-brand-mist">·</span>
                                            <a href="{{ route('sites.index') }}" wire:navigate class="font-semibold text-brand-sage hover:text-brand-ink">{{ trans_choice(':n site|:n sites', $count, ['n' => $count]) }}</a>
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center justify-end gap-3">
                                    @if ($editingId === (string) $account->id)
                                        <button type="button" wire:click="saveEdit" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Save') }}
                                        </button>
                                        <button type="button" wire:click="cancelEdit" class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                                    @else
                                        <button type="button" wire:click="startEdit('{{ $account->id }}')" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-ink hover:text-brand-sage">
                                            <x-heroicon-o-pencil-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Edit') }}
                                        </button>
                                        <button type="button" wire:click="openConfirmActionModal('unlinkAccount', ['{{ $account->id }}'], @js(__('Unlink account')), @js(__('Unlink this account? Deploy keys and webhooks for sites using this identity are unchanged.')), @js(__('Unlink')), true)" class="inline-flex items-center gap-1.5 text-xs font-semibold text-red-600 hover:text-red-700 hover:underline">
                                            <x-heroicon-o-link-slash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Unlink') }}
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach

                        @foreach ($provider['pats'] as $pat)
                            <li wire:key="sc-pat-{{ $pat->id }}" class="flex flex-col gap-3 px-6 py-3.5 transition-colors hover:bg-brand-sand/15 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-7">
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                        <span class="inline-flex items-center rounded-md border border-violet-200 bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-700">{{ __('PAT') }}</span>
                                        <span class="truncate text-sm font-semibold text-brand-ink">{{ $pat->nickname ?? '—' }}</span>
                                        @if ($editingPatId === (string) $pat->id)
                                            <x-text-input wire:model="editPatLabel" class="block w-full max-w-xs text-xs" placeholder="{{ __('Label (optional)') }}" />
                                        @elseif ($pat->label)
                                            <span class="inline-flex items-center rounded-md bg-brand-sand/60 px-1.5 py-0.5 text-[10px] font-medium text-brand-moss">{{ $pat->label }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-brand-mist">
                                        <span>{{ __('Added :time', ['time' => $pat->created_at?->diffForHumans() ?? '—']) }}</span>
                                        @if ($pat->api_base_url)
                                            <span class="text-brand-mist">·</span>
                                            <span class="truncate font-mono">{{ $pat->api_base_url }}</span>
                                        @endif
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center justify-end gap-3">
                                    @if ($editingPatId === (string) $pat->id)
                                        <button type="button" wire:click="saveEditPat" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40">
                                            <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Save') }}
                                        </button>
                                        <button type="button" wire:click="cancelEditPat" class="text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                                    @else
                                        <button type="button" wire:click="startEditPat('{{ $pat->id }}')" class="inline-flex items-center gap-1.5 text-xs font-semibold text-brand-ink hover:text-brand-sage">
                                            <x-heroicon-o-pencil-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Edit') }}
                                        </button>
                                        <button type="button" wire:click="openConfirmActionModal('unlinkPat', ['{{ $pat->id }}'], @js(__('Remove token')), @js(__('Remove this personal access token? Sites using this token will lose access until re-pointed.')), @js(__('Remove')), true)" class="inline-flex items-center gap-1.5 text-xs font-semibold text-red-600 hover:text-red-700 hover:underline">
                                            <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                            {{ __('Remove') }}
                                        </button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @empty
            <div class="dply-card overflow-hidden">
                <div class="px-6 py-12 text-center sm:px-7">
                    <span class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-code-bracket-square class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No Git providers available') }}</p>
                    <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                        {{ __('Ask an administrator to configure GitHub, GitLab, or Bitbucket OAuth, or add a personal access token for any provider.') }}
                    </p>
                </div>
            </div>
        @endforelse
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
