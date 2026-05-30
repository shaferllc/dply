<div>
    <x-livewire-validation-errors />

    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Profile'), 'href' => route('settings.profile'), 'icon' => 'user-circle'],
        ['label' => __('CLI'), 'icon' => 'command-line'],
    ]" />

    @if ($organizations->isEmpty())
        <section class="dply-card mt-6 overflow-hidden p-6 sm:p-8">
            <p class="text-sm text-brand-moss">{{ __('Org admin access is required to manage CLI authentications.') }}</p>
        </section>
    @else
        <div class="mt-6 space-y-6">
            <section class="dply-card overflow-hidden">
                <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:px-7">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                        <x-heroicon-o-arrow-down-tray class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Install') }}</p>
                        <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Get the dply CLI') }}</h2>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            {{ __('Requires Node 18+. Run `dply login` — your browser opens here, you approve once, and the terminal drops into `dply shell`. Press Enter for numbered menus, or type commands directly.') }}
                        </p>
                    </div>
                </div>
                <div class="space-y-4 px-6 py-5 sm:px-7">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('1. Install') }}</p>
                        @php $installUrl = route('cli.install'); @endphp
                        <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-4 py-3 text-sm text-brand-cream"><code>curl -fsSL {{ $installUrl }} | bash -s -- --login</code></pre>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('The CLI is hosted by this dply instance — not npm. The script downloads /cli/dply-cli.tgz and installs it globally. Node 18+ required.') }}
                        </p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('2. Sign in') }}</p>
                        <p class="mt-2 text-xs leading-relaxed text-brand-moss">
                            {{ __('If you used `--login` above, you are already authenticated. Otherwise run:') }}
                        </p>
                        <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-4 py-3 text-sm text-brand-cream"><code>dply login --base-url {{ $appUrl }}</code></pre>
                    </div>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('3. Verify') }}</p>
                        <pre class="mt-2 overflow-x-auto rounded-xl border border-brand-ink/10 bg-brand-ink px-4 py-3 text-sm text-brand-cream"><code>dply account show
dply account sessions
dply server list</code></pre>
                    </div>
                </div>
            </section>

            <section class="dply-card overflow-hidden">
                <div class="flex flex-col gap-4 border-b border-brand-ink/10 bg-brand-sand/20 px-6 py-5 sm:flex-row sm:items-start sm:justify-between sm:px-7">
                    <div class="flex min-w-0 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                            <x-heroicon-o-shield-check class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-brand-sage">{{ __('Sessions') }}</p>
                            <h2 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('CLI authentications') }}</h2>
                            <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                                {{ __('Tokens from device-flow login (“:name”). Revoke to sign a machine out immediately.', ['name' => $cliTokenName]) }}
                            </p>
                        </div>
                    </div>
                    @if ($organizations->count() > 1)
                        <select
                            wire:model.live="organization_id"
                            class="block min-w-[12rem] rounded-lg border-brand-ink/15 bg-white text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30"
                        >
                            @foreach ($organizations as $org)
                                <option value="{{ $org->id }}">{{ $org->name }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>

                @if ($cliTokens->isEmpty())
                    <div class="px-6 py-10 text-center sm:px-7">
                        <p class="text-sm font-medium text-brand-ink">{{ __('No CLI sessions yet') }}</p>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-brand-moss">
                            {{ __('Run `dply login` from a terminal and approve the device to create the first session.') }}
                        </p>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($cliTokens as $token)
                            <li wire:key="cli-token-{{ $token->id }}" class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-7">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm text-brand-ink">{{ $token->token_prefix }}…</p>
                                    <p class="mt-1 text-xs text-brand-moss">
                                        {{ $token->user?->email ?? __('Unknown') }}
                                        · {{ $token->created_at?->diffForHumans() }}
                                        @if ($token->last_used_at)
                                            · {{ __('Last used :time', ['time' => $token->last_used_at->diffForHumans()]) }}
                                        @endif
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    wire:click="openConfirmActionModal('revokeCliToken', [@js((string) $token->id)], @js(__('Revoke this CLI session?')), @js(__('That machine loses API access immediately. Re-run `dply login` to reconnect.')), @js(__('Revoke session')), true)"
                                    class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-800 hover:bg-rose-100"
                                >
                                    <x-heroicon-o-x-circle class="h-3.5 w-3.5" />
                                    {{ __('Revoke') }}
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    @endif

    @include('livewire.partials.confirm-action-modal')
</div>
