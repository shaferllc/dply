{{-- Security: the oauth block. Extracted so the page layout
     can change without touching the controls. --}}
        {{-- OAuth sign-in. The empty-providers guard lives on the card in
             body.blade.php, so an unconfigured deployment renders no card at
             all rather than an empty one. --}}
            <div class="border-b border-brand-ink/10">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-arrow-top-right-on-square"
                    :title="__('OAuth sign-in')"
                    :count="$linkedOAuth > 0 ? $linkedOAuth : null"
                    :note="__('Sign in with the same GitHub, GitLab, or Bitbucket account you use for Git.')"
                />
                <div class="px-3 py-2.5 sm:px-4">
                    @error('unlink')
                        <p class="mb-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    <div class="overflow-hidden rounded-lg border border-brand-ink/10 bg-white">
                        <ul class="divide-y divide-brand-ink/10">
                            @foreach ($oauthProviders as $p)
                                @php $linked = $socialAccounts->where('provider', $p['id']); @endphp
                                <li>
                                    <div class="flex flex-wrap items-center gap-2 bg-brand-sand/30 px-2.5 py-1.5">
                                        <x-oauth-provider-icon :provider="$p['id']" size="h-4 w-4" />
                                        <span class="text-sm font-semibold text-brand-ink">{{ $p['name'] }}</span>
                                        @if ($linked->isNotEmpty())
                                            <span class="inline-flex items-center gap-1 text-xs font-medium text-brand-forest">
                                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-brand-sage" aria-hidden="true"></span>
                                                {{ trans_choice(':n linked|:n linked', $linked->count(), ['n' => $linked->count()]) }}
                                            </span>
                                        @else
                                            <span class="text-xs text-brand-mist">{{ __('Not linked') }}</span>
                                        @endif
                                        <a
                                            href="{{ route('oauth.redirect', ['provider' => $p['id'], 'return' => 'security']) }}"
                                            class="ms-auto inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-brand-ink/15 bg-white px-2 text-xs font-semibold text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/50"
                                        >
                                            <x-heroicon-o-link class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('Link account') }}
                                        </a>
                                    </div>
                                    @foreach ($linked as $account)
                                        <div class="flex items-center justify-between gap-3 border-t border-brand-ink/10 px-2.5 py-1.5 transition-colors hover:bg-brand-sand/15">
                                            <span class="truncate text-xs font-medium text-brand-ink">{{ $account->nickname ?? $account->provider_id }}</span>
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('unlinkOAuthAccount', [{{ $account->id }}], @js(__('Unlink account')), @js(__('Unlink this account? You can link it again later from this page.')), @js(__('Unlink')), true)"
                                                class="inline-flex h-6 shrink-0 items-center gap-1 rounded-md border border-rose-200 bg-white px-2 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                            >
                                                <x-heroicon-o-link-slash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                                {{ __('Unlink') }}
                                            </button>
                                        </div>
                                    @endforeach
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>

