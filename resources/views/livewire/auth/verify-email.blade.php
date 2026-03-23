<div>
    <p class="mb-5 text-sm text-stone-600">
        {{ __('Thanks for signing up! Before getting started, could you verify your email address by clicking on the link we just emailed to you? If you didn\'t receive the email, we will gladly send you another.') }}
    </p>
    <p class="mb-5 text-sm text-stone-500">
        {{ __('Creating servers, organizations, and other actions in this app require a verified email address.') }}
    </p>
    @if (session('error'))
        <p class="mb-5 font-medium text-sm text-red-600" role="alert">
            {{ session('error') }}
        </p>
    @endif
    @if (session('status') == 'verification-link-sent')
        <p class="mb-5 font-medium text-sm text-emerald-600">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </p>
    @endif
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pt-2">
        <button type="button" wire:click="sendNotification" class="inline-flex items-center px-4 py-2 bg-stone-900 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-stone-800">
            {{ __('Resend Verification Email') }}
        </button>
        <form method="POST" action="{{ route('logout') }}" class="inline">
            @csrf
            <button type="submit" class="text-sm text-stone-600 hover:text-stone-900 focus:outline-none focus:ring-2 focus:ring-stone-500 focus:ring-offset-2 rounded">
                {{ __('Log Out') }}
            </button>
        </form>
    </div>
</div>
