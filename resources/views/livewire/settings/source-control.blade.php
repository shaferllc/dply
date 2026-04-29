<div>
    <x-livewire-validation-errors />

    <nav class="text-sm text-brand-moss mb-6" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-2">
            <li><a href="{{ route('dashboard') }}" class="hover:text-brand-ink transition-colors">{{ __('Dashboard') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li><a href="{{ route('profile.edit') }}" class="hover:text-brand-ink transition-colors" wire:navigate>{{ __('Profile') }}</a></li>
            <li class="text-brand-mist" aria-hidden="true">/</li>
            <li class="text-brand-ink font-medium">{{ __('Source control') }}</li>
        </ol>
    </nav>

    <header class="mb-8">
        <h1 class="text-2xl font-semibold text-brand-ink">{{ __('Source control') }}</h1>
        <p class="mt-2 text-sm text-brand-moss max-w-2xl leading-relaxed">
            {{ __('Link GitHub, GitLab, or Bitbucket when OAuth is enabled—for repository access, deploy identity, and sign-in. Before linking a Git account, sign in to the correct provider in another tab if you use multiple.') }}
            <a href="{{ route('docs.source-control') }}" class="text-brand-sage font-medium hover:text-brand-ink underline underline-offset-2">{{ __('Read the deploy flow docs') }}</a>
        </p>
        @if (auth()->user()->currentOrganization())
            <p class="mt-3 text-sm text-brand-moss max-w-2xl leading-relaxed">
                {{ __('To add API tokens for cloud or server providers (DigitalOcean, Hetzner, AWS, and others), use') }}
                <a href="{{ route('credentials.index') }}" wire:navigate class="font-medium text-brand-sage hover:text-brand-ink underline underline-offset-2">{{ __('Server providers') }}</a>.
            </p>
        @endif
    </header>

    @error('unlink')
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">{{ $message }}</div>
    @enderror

    <div class="space-y-8">
        @forelse ($this->providers as $provider)
                @php($count = $this->repositoryCount($provider['host']))
                <section class="dply-card overflow-hidden" aria-labelledby="sc-heading-{{ $provider['id'] }}">
                    <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/15 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white border border-brand-ink/10 text-brand-ink" aria-hidden="true">
                                @if ($provider['id'] === 'github')
                                    <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.531 1.032 1.531 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z" clip-rule="evenodd"/></svg>
                                @elseif ($provider['id'] === 'bitbucket')
                                    <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M2.65 3A.65.65 0 002 3.65v16.7c0 .36.29.65.65.65h18.7a.65.65 0 00.65-.65V3.65A.65.65 0 0021.35 3H2.65zm4.34 5.36c0 .07.05.13.12.15l1.6.37 1.46 6.93c.02.1.1.17.2.17h2.1c.1 0 .18-.07.2-.17l1.46-6.93 1.6-.37a.16.16 0 00.12-.15v-1.2a.16.16 0 00-.12-.15l-5.24-1.2a.16.16 0 00-.2.15v1.21z"/></svg>
                                @else
                                    <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M23.955 13.587l-1.342-4.135-2.664-8.189a.455.455 0 00-.867 0L16.418 9.45H7.582L4.919 1.263C4.783.84 4.262.647 3.84.784L3.045 1.01l-2.664 8.189-1.342 4.135a.924.924 0 00.331 1.023L12 23.054l11.624-8.444a.92.92 0 00.331-1.023"/></svg>
                                @endif
                            </span>
                            <h2 id="sc-heading-{{ $provider['id'] }}" class="text-lg font-semibold text-brand-ink truncate">{{ $provider['name'] }}</h2>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($provider['id'] === 'gitlab')
                                <button
                                    type="button"
                                    disabled
                                    class="inline-flex items-center justify-center rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-mist cursor-not-allowed opacity-70"
                                    title="{{ __('Self-hosted GitLab OAuth is not available yet.') }}"
                                >{{ __('Link self-hosted GitLab') }}</button>
                            @endif
                            <a
                                href="{{ route('oauth.redirect', ['provider' => $provider['id']]) }}"
                                class="inline-flex items-center justify-center rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-sm hover:bg-brand-forest transition-colors"
                            >{{ __('Link :name account', ['name' => $provider['name']]) }}</a>
                        </div>
                    </div>
                    <div class="px-6 py-4">
                        <p class="text-xs text-brand-moss leading-relaxed">
                            {{ __('Before you link a new :name account, make sure you are logged in to the correct Git account in your browser.', ['name' => $provider['name']]) }}
                        </p>
                    </div>
                    <div class="overflow-x-auto border-t border-brand-ink/10">
                        @if ($provider['accounts']->isEmpty())
                            <p class="px-6 py-12 text-center text-sm text-brand-moss">{{ __('No linked accounts yet.') }}</p>
                        @else
                            <table class="min-w-full text-left text-sm">
                                <thead>
                                    <tr class="border-b border-brand-ink/10 bg-brand-sand/20 text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                        <th class="px-6 py-3 font-medium">{{ __('Label') }}</th>
                                        <th class="px-6 py-3 font-medium">{{ __('Name') }}</th>
                                        <th class="px-6 py-3 font-medium">{{ __('Installed repositories') }}</th>
                                        <th class="px-6 py-3 font-medium">{{ __('Linked') }}</th>
                                        <th class="px-6 py-3 font-medium text-right">{{ __('Actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-brand-ink/10 text-brand-ink">
                                    @foreach ($provider['accounts'] as $account)
                                        <tr wire:key="sc-{{ $account->id }}">
                                            <td class="px-6 py-3 align-top">
                                                @if ($editingId === $account->id)
                                                    <x-text-input wire:model="editLabel" class="block w-full min-w-[8rem] text-sm" placeholder="—" />
                                                @else
                                                    <span class="text-brand-ink">{{ $account->label ?? '—' }}</span>
                                                @endif
                                            </td>
                                            <td class="px-6 py-3 align-top font-medium">{{ $account->nickname ?? '—' }}</td>
                                            <td class="px-6 py-3 align-top">
                                                @if ($count > 0)
                                                    <a href="{{ route('sites.index') }}" wire:navigate class="font-medium text-brand-sage hover:text-brand-ink hover:underline">{{ $count }}</a>
                                                @else
                                                    <span class="text-brand-moss">0</span>
                                                @endif
                                            </td>
                                            <td class="px-6 py-3 align-top text-brand-moss whitespace-nowrap">{{ $account->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                                            <td class="px-6 py-3 align-top text-right">
                                                @if ($editingId === $account->id)
                                                    <div class="flex flex-wrap justify-end gap-2">
                                                        <button type="button" wire:click="saveEdit" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Save') }}</button>
                                                        <button type="button" wire:click="cancelEdit" class="inline-flex items-center rounded-lg px-3 py-1.5 text-xs font-medium text-brand-moss hover:text-brand-ink">{{ __('Cancel') }}</button>
                                                    </div>
                                                @else
                                                    <div class="flex flex-wrap justify-end gap-2">
                                                        <button type="button" wire:click="startEdit({{ $account->id }})" class="inline-flex items-center rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink hover:bg-brand-sand/40">{{ __('Edit') }}</button>
                                                        <button type="button" wire:click="openConfirmActionModal('unlinkAccount', [{{ $account->id }}], @js(__('Unlink account')), @js(__('Unlink this account? Deploy keys and webhooks for sites using this identity are unchanged.')), @js(__('Unlink')), true)" class="inline-flex items-center rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700">{{ __('Unlink') }}</button>
                                                    </div>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>
                </section>
        @empty
            <div class="rounded-2xl border border-brand-ink/10 bg-white px-6 py-10 text-center text-sm text-brand-moss shadow-sm">
                {{ __('No Git OAuth providers are enabled for this application. Ask an administrator to configure GitHub, GitLab, or Bitbucket OAuth.') }}
            </div>
        @endforelse
    </div>

    <x-slot name="modals">
        @include('livewire.partials.confirm-action-modal')
    </x-slot>
</div>
