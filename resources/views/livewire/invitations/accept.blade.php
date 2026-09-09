@php
    $org = $invitation?->organization;
    $inviterName = $invitation?->inviter?->name ?? $invitation?->inviter?->email ?? __('Someone');
@endphp

{{-- Single root: this view renders under the app layout when signed in and the
     guest layout when not, so it must not rely on either one's chrome. --}}
<div class="mx-auto w-full max-w-xl px-4 py-10 sm:px-6">
    @if ($invitation)
        <div class="dply-card overflow-hidden p-0">
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/20 px-5 py-5 sm:px-6">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-sage/15 text-brand-forest ring-1 ring-brand-sage/25">
                    <x-heroicon-o-envelope-open class="h-5 w-5" aria-hidden="true" />
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.18em] text-brand-sage">{{ __('Invitation') }}</p>
                    <h1 class="mt-1 text-lg font-semibold tracking-tight text-brand-ink">
                        {{ __('Join :org', ['org' => $org->name]) }}
                    </h1>
                    <p class="mt-1 text-sm leading-relaxed text-brand-moss">
                        {{ __(':inviter invited :email to :org on :app.', [
                            'inviter' => $inviterName,
                            'email' => $invitation->email,
                            'org' => $org->name,
                            'app' => config('app.name'),
                        ]) }}
                    </p>
                </div>
            </div>

            {{-- What they're actually getting. --}}
            <dl class="grid gap-px border-b border-brand-ink/10 bg-brand-ink/5 sm:grid-cols-3">
                <div class="bg-white px-4 py-3">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Organization') }}</dt>
                    <dd class="mt-0.5 truncate text-sm font-semibold text-brand-ink">{{ $org->name }}</dd>
                </div>
                <div class="bg-white px-4 py-3">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Role') }}</dt>
                    <dd class="mt-0.5 text-sm font-semibold capitalize text-brand-ink">{{ $invitation->role }}</dd>
                </div>
                <div class="bg-white px-4 py-3">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Team') }}</dt>
                    <dd class="mt-0.5 truncate text-sm font-semibold text-brand-ink">
                        {{ $invitation->team?->name ?? __('—') }}
                    </dd>
                </div>
            </dl>

            <div class="px-5 py-5 sm:px-6">
                @if ($state === 'guest')
                    <p class="text-sm leading-relaxed text-brand-moss">
                        {{ __('Create an account with :email to accept, or sign in if you already have one.', ['email' => $invitation->email]) }}
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <a
                            href="{{ $this->registerUrl() }}"
                            wire:navigate
                            class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                        >
                            <x-heroicon-o-user-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Create account') }}
                        </a>
                        <x-outline-link href="{{ route('login') }}" wire:navigate>
                            <x-heroicon-o-arrow-right-end-on-rectangle class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                            {{ __('I already have an account') }}
                        </x-outline-link>
                    </div>
                @elseif ($state === 'mismatch')
                    <div class="rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3">
                        <p class="text-sm leading-relaxed text-amber-900">{{ $error }}</p>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button
                                type="submit"
                                class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                            >
                                <x-heroicon-o-arrow-left-start-on-rectangle class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Sign out and switch account') }}
                            </button>
                        </form>
                        <x-outline-link href="{{ route('dashboard') }}" wire:navigate>
                            {{ __('Back to dashboard') }}
                        </x-outline-link>
                    </div>
                @else
                    <p class="text-sm leading-relaxed text-brand-moss">
                        {{ $invitation->team
                            ? __('Accepting adds you to :org and the team :team.', ['org' => $org->name, 'team' => $invitation->team->name])
                            : __('Accepting adds you to :org.', ['org' => $org->name]) }}
                    </p>
                    <div class="mt-4 flex flex-wrap gap-3">
                        <button
                            type="button"
                            wire:click="accept"
                            wire:loading.attr="disabled"
                            wire:target="accept"
                            class="inline-flex min-w-[7rem] items-center justify-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest disabled:opacity-70"
                        >
                            <span wire:loading.remove wire:target="accept" class="inline-flex items-center gap-2">
                                <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                                {{ __('Accept invitation') }}
                            </span>
                            <span wire:loading wire:target="accept" class="inline-flex items-center gap-2">
                                <x-spinner variant="cream" size="sm" />
                                {{ __('Accepting…') }}
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="decline"
                            wire:loading.attr="disabled"
                            wire:target="decline"
                            class="inline-flex min-w-[7rem] items-center justify-center gap-2 rounded-xl border border-brand-ink/15 bg-white px-4 py-2 text-sm font-medium text-brand-ink shadow-sm transition-colors hover:bg-brand-sand/40 disabled:opacity-70"
                        >
                            <span wire:loading.remove wire:target="decline">{{ __('Decline') }}</span>
                            <span wire:loading wire:target="decline" class="inline-flex items-center gap-2">
                                <x-spinner variant="forest" size="sm" />
                                {{ __('Declining…') }}
                            </span>
                        </button>
                    </div>
                @endif
            </div>

            <div class="border-t border-brand-ink/10 bg-brand-sand/25 px-5 py-3 text-xs text-brand-moss sm:px-6">
                {{ __('This invitation expires :date.', ['date' => $invitation->expires_at->format('M j, Y')]) }}
            </div>
        </div>
    @else
        {{-- invalid / expired --}}
        <div class="dply-card p-6 text-center">
            <span class="mx-auto inline-flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                <x-heroicon-o-exclamation-triangle class="h-5 w-5" aria-hidden="true" />
            </span>
            <h1 class="mt-3 text-base font-semibold text-brand-ink">
                {{ $state === 'expired' ? __('Invitation expired') : __('Invitation not found') }}
            </h1>
            <p class="mx-auto mt-1 max-w-sm text-sm leading-relaxed text-brand-moss">{{ $error }}</p>
            <div class="mt-4 flex flex-wrap justify-center gap-3">
                @auth
                    <x-outline-link href="{{ route('organizations.index') }}" wire:navigate>
                        {{ __('Your organizations') }}
                    </x-outline-link>
                @else
                    <x-outline-link href="{{ route('login') }}" wire:navigate>
                        {{ __('Sign in') }}
                    </x-outline-link>
                @endauth
            </div>
        </div>
    @endif
</div>
