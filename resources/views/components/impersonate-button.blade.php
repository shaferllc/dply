@props([
    'user',
    'label' => null,
    'variant' => 'default', // default | subtle
])

@php
    $isAdmin = in_array(\Illuminate\Support\Str::lower((string) $user->email), \App\Support\Admin\PlatformAdmins::emails(), true);
    $isSelf = auth()->id() === $user->getKey();

    $classes = match ($variant) {
        'subtle' => 'inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink',
        default => 'inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-brand-forest/90',
    };
@endphp

{{-- Hidden for platform admins (privilege safety) and for yourself. The route
     re-checks both, so this is UI affordance only. --}}
@unless ($isAdmin || $isSelf)
    <form method="POST" action="{{ route('admin.impersonate.start', $user) }}" {{ $attributes }}>
        @csrf
        <button type="submit" class="{{ $classes }}">
            <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10 8a3 3 0 100-6 3 3 0 000 6zM3.465 14.493a1.23 1.23 0 00.41 1.412A9.957 9.957 0 0010 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 00-13.074.003z" />
            </svg>
            {{ $label ?? __('Impersonate') }}
        </button>
    </form>
@endunless
