{{--
  Sidebar for Step 2 / custom (BYO) mode.
  Required: $form, $connectionState, $connectionMessage
--}}
<div class="space-y-4">
    <div class="rounded-2xl border border-slate-200 bg-white p-4">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Connection check') }}</p>
        @switch($connectionState)
            @case('success')
                <p class="mt-2 text-sm font-medium text-emerald-700">{{ __('SSH verified') }}</p>
                @break
            @case('warning')
                <p class="mt-2 text-sm font-medium text-amber-700">{{ __('Connected, but check the warning') }}</p>
                @break
            @case('error')
                <p class="mt-2 text-sm font-medium text-rose-700">{{ __('Could not authenticate') }}</p>
                @break
            @default
                <p class="mt-2 text-sm text-slate-600">{{ __('Run "Test connection" to verify the SSH credentials before continuing.') }}</p>
        @endswitch
        @if ($connectionMessage !== '')
            <p class="mt-2 text-xs leading-5 text-slate-600">{{ $connectionMessage }}</p>
        @endif
    </div>

    <div class="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 text-sm text-slate-700">
        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">{{ __('Tips') }}</p>
        <ul class="mt-2 space-y-1.5">
            <li>• {{ __('SSH user usually root, ubuntu, or a sudo-enabled deploy user.') }}</li>
            <li>• {{ __('Private key is stored encrypted at rest and only used to reach this server.') }}</li>
            <li>• {{ __('Docker host? Skip the stack install — Step 3 is bypassed automatically.') }}</li>
        </ul>
    </div>
</div>
