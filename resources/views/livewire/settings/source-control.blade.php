<div>
    <x-livewire-validation-errors />

    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Profile'), 'href' => route('profile.edit'), 'icon' => 'user-circle'],
        ['label' => __('Source control'), 'icon' => 'code-bracket-square'],
    ]" />

    <div class="space-y-8">
        @error('unlink')
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $message }}</div>
        @enderror

        <div class="dply-card overflow-hidden">
            <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                <div class="lg:col-span-4">
                    <h2 class="text-lg font-semibold text-brand-ink">{{ __('Source control') }}</h2>
                    <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                        {{ __('Link GitHub, GitLab, or Bitbucket via OAuth or add a personal access token. Tokens unlock clone, browse, and webhook automation — and let you connect self-hosted GitLab or GitHub Enterprise hosts that OAuth does not cover.') }}
                    </p>
                </div>
                <div class="lg:col-span-8 space-y-4">
                    <div>
                        <x-docs-link doc-route="docs.markdown" doc-slug="source-control">
                            <x-heroicon-o-document-text class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('Source control docs') }}
                        </x-docs-link>
                    </div>
                    @if (auth()->user()->currentOrganization())
                        <p class="text-sm text-brand-moss leading-relaxed">
                            {{ __('To add API tokens for cloud or server providers (DigitalOcean, Hetzner, AWS, and others), use') }}
                            <a href="{{ route('credentials.index') }}" wire:navigate class="font-medium text-brand-sage hover:text-brand-ink underline underline-offset-2">{{ __('Server providers') }}</a>.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        @forelse ($this->providers as $provider)
            @php($count = $this->repositoryCount($provider['host']))
            <section class="dply-card overflow-hidden" aria-labelledby="sc-heading-{{ $provider['id'] }}">
                <div class="grid lg:grid-cols-12 gap-8 p-6 sm:p-8">
                    <div class="lg:col-span-4 min-w-0">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-brand-ink/10 bg-white text-brand-ink" aria-hidden="true">
                                <x-oauth-provider-icon :provider="$provider['id']" size="h-6 w-6" />
                            </span>
                            <div class="min-w-0">
                                <h2 id="sc-heading-{{ $provider['id'] }}" class="text-lg font-semibold text-brand-ink">{{ $provider['name'] }}</h2>
                                <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                    {{ __('Link an OAuth account, or paste a personal access token for self-hosted hosts and machine-user workflows.') }}
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="lg:col-span-8 space-y-6 min-w-0">
                        <div class="flex flex-wrap items-center justify-end gap-2">
                            @if ($provider['oauth_enabled'])
                                <a
                                    href="{{ route('oauth.redirect', ['provider' => $provider['id']]) }}"
                                    class="inline-flex items-center justify-center rounded-xl border border-transparent bg-brand-ink px-5 py-2.5 text-sm font-semibold text-brand-cream shadow-md hover:bg-brand-forest transition-colors"
                                >{{ __('Link :name account', ['name' => $provider['name']]) }}</a>
                            @endif
                            <button
                                type="button"
                                wire:click="startAddPat('{{ $provider['id'] }}')"
                                class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-5 py-2.5 text-sm font-semibold text-brand-ink hover:bg-brand-sand/40 transition-colors"
                            >{{ __('Add personal access token') }}</button>
                        </div>

                        @if ($addingPatProvider === $provider['id'])
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 p-6 space-y-4">
                                <div>
                                    <h3 class="text-sm font-semibold text-brand-ink">{{ __('Add a :name personal access token', ['name' => $provider['name']]) }}</h3>
                                    <p class="mt-1 text-xs text-brand-moss leading-relaxed">
                                        @if ($provider['id'] === 'github')
                                            {{ __('Classic PATs need repo and admin:repo_hook scopes. Fine-grained tokens need Contents (Read), Metadata (Read), and Webhooks (Read & Write) for the target repositories.') }}
                                        @elseif ($provider['id'] === 'gitlab')
                                            {{ __('Token needs the api scope. Group-scoped tokens cover every project under that group.') }}
                                        @else
                                            {{ __('App password or workspace access token with repository:read and webhook permissions.') }}
                                        @endif
                                    </p>
                                </div>

                                <div class="grid sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-brand-moss mb-1" for="pat-label-{{ $provider['id'] }}">{{ __('Label (optional)') }}</label>
                                        <x-text-input id="pat-label-{{ $provider['id'] }}" wire:model="patLabel" class="block w-full text-sm" placeholder="{{ __('e.g. machine user, work account') }}" />
                                        @error('patLabel') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-brand-moss mb-1" for="pat-token-{{ $provider['id'] }}">{{ __('Token') }}</label>
                                        <x-text-input id="pat-token-{{ $provider['id'] }}" type="password" wire:model="patToken" class="block w-full text-sm font-mono" autocomplete="off" />
                                        @error('patToken') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>

                                @if ($provider['id'] !== 'bitbucket')
                                    <div>
                                        <label class="block text-xs font-medium text-brand-moss mb-1" for="pat-base-{{ $provider['id'] }}">
                                            {{ $provider['id'] === 'github'
                                                ? __('API base URL (optional, for GitHub Enterprise)')
                                                : __('API base URL (optional, for self-hosted GitLab)') }}
                                        </label>
                                        <x-text-input
                                            id="pat-base-{{ $provider['id'] }}"
                                            wire:model="patApiBaseUrl"
                                            class="block w-full text-sm font-mono"
                                            placeholder="{{ $provider['id'] === 'github' ? 'https://github.example.com/api/v3' : 'https://gitlab.example.com' }}"
                                        />
                                        @error('patApiBaseUrl') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                                        <p class="mt-1 text-xs text-brand-moss">{{ __('Leave blank for the public :host host.', ['host' => $provider['host']]) }}</p>
                                    </div>
                                @endif

                                <div class="flex flex-wrap justify-end gap-2 pt-2">
                                    <button type="button" wire:click="cancelAddPat" class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                                    <button type="button" wire:click="savePat" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-brand-ink px-4 py-1.5 text-xs font-semibold text-brand-cream hover:bg-brand-forest">{{ __('Validate and save') }}</button>
                                </div>
                            </div>
                        @endif

                        @php($hasAny = $provider['accounts']->isNotEmpty() || $provider['pats']->isNotEmpty())
                        @if (! $hasAny)
                            <div class="rounded-2xl border border-brand-ink/10 bg-brand-sand/10 px-6 py-12 text-center">
                                <p class="text-sm text-brand-moss">{{ __('No linked accounts or tokens yet.') }}</p>
                            </div>
                        @else
                            <div class="overflow-x-auto rounded-xl border border-brand-mist -mx-1 sm:mx-0">
                                <table class="min-w-full text-left text-sm">
                                    <thead>
                                        <tr class="border-b border-brand-mist bg-brand-sand/20 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                            <th class="px-4 py-3 font-medium">{{ __('Type') }}</th>
                                            <th class="px-4 py-3 font-medium">{{ __('Label') }}</th>
                                            <th class="px-4 py-3 font-medium">{{ __('Name') }}</th>
                                            <th class="px-4 py-3 font-medium">{{ __('Installed repositories') }}</th>
                                            <th class="px-4 py-3 font-medium">{{ __('Added') }}</th>
                                            <th class="px-4 py-3 font-medium text-right">{{ __('Actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-mist/80 text-brand-ink bg-white">
                                        @foreach ($provider['accounts'] as $account)
                                            <tr wire:key="sc-oauth-{{ $account->id }}">
                                                <td class="px-4 py-3 align-top">
                                                    <span class="inline-flex items-center rounded-full bg-brand-forest/10 px-2 py-0.5 text-xs font-medium text-brand-forest">{{ __('OAuth') }}</span>
                                                </td>
                                                <td class="px-4 py-3 align-top">
                                                    @if ($editingId === (string) $account->id)
                                                        <x-text-input wire:model="editLabel" class="block w-full min-w-[8rem] text-sm" placeholder="—" />
                                                    @else
                                                        <span class="text-brand-ink">{{ $account->label ?? '—' }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-3 align-top font-medium">{{ $account->nickname ?? '—' }}</td>
                                                <td class="px-4 py-3 align-top">
                                                    @if ($count > 0)
                                                        <a href="{{ route('sites.index') }}" wire:navigate class="font-medium text-brand-sage hover:text-brand-ink hover:underline">{{ $count }}</a>
                                                    @else
                                                        <span class="text-brand-moss">0</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-3 align-top text-brand-moss whitespace-nowrap">{{ $account->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                                <td class="px-4 py-3 align-top text-right">
                                                    @if ($editingId === (string) $account->id)
                                                        <div class="flex flex-wrap justify-end gap-2">
                                                            <button type="button" wire:click="saveEdit" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Save') }}</button>
                                                            <button type="button" wire:click="cancelEdit" class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                                                        </div>
                                                    @else
                                                        <div class="flex flex-wrap justify-end gap-2">
                                                            <button type="button" wire:click="startEdit('{{ $account->id }}')" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Edit') }}</button>
                                                            <button type="button" wire:click="openConfirmActionModal('unlinkAccount', ['{{ $account->id }}'], @js(__('Unlink account')), @js(__('Unlink this account? Deploy keys and webhooks for sites using this identity are unchanged.')), @js(__('Unlink')), true)" class="inline-flex items-center rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">{{ __('Unlink') }}</button>
                                                        </div>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach

                                        @foreach ($provider['pats'] as $pat)
                                            <tr wire:key="sc-pat-{{ $pat->id }}">
                                                <td class="px-4 py-3 align-top">
                                                    <span class="inline-flex items-center rounded-full bg-brand-sage/10 px-2 py-0.5 text-xs font-medium text-brand-sage">{{ __('Token') }}</span>
                                                </td>
                                                <td class="px-4 py-3 align-top">
                                                    @if ($editingPatId === (string) $pat->id)
                                                        <x-text-input wire:model="editPatLabel" class="block w-full min-w-[8rem] text-sm" placeholder="—" />
                                                    @else
                                                        <span class="text-brand-ink">{{ $pat->label ?? '—' }}</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-3 align-top font-medium">
                                                    {{ $pat->nickname ?? '—' }}
                                                    @if ($pat->api_base_url)
                                                        <div class="mt-0.5 text-xs text-brand-moss font-normal">{{ $pat->api_base_url }}</div>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-3 align-top">
                                                    <span class="text-brand-moss">—</span>
                                                </td>
                                                <td class="px-4 py-3 align-top text-brand-moss whitespace-nowrap">{{ $pat->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                                <td class="px-4 py-3 align-top text-right">
                                                    @if ($editingPatId === (string) $pat->id)
                                                        <div class="flex flex-wrap justify-end gap-2">
                                                            <button type="button" wire:click="saveEditPat" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Save') }}</button>
                                                            <button type="button" wire:click="cancelEditPat" class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                                                        </div>
                                                    @else
                                                        <div class="flex flex-wrap justify-end gap-2">
                                                            <button type="button" wire:click="startEditPat('{{ $pat->id }}')" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Edit') }}</button>
                                                            <button type="button" wire:click="openConfirmActionModal('unlinkPat', ['{{ $pat->id }}'], @js(__('Remove token')), @js(__('Remove this personal access token? Sites using this token will lose access until re-pointed.')), @js(__('Remove')), true)" class="inline-flex items-center rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">{{ __('Remove') }}</button>
                                                        </div>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </section>
        @empty
            <div class="dply-card overflow-hidden">
                <div class="p-6 sm:p-8 text-center text-sm text-brand-moss">
                    {{ __('No Git providers are available. Ask an administrator to configure GitHub, GitLab, or Bitbucket OAuth, or add a personal access token for any provider.') }}
                </div>
            </div>
        @endforelse
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
