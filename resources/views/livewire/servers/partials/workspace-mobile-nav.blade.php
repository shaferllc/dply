@props([
    'server',
    'active',
])

<div class="mb-6 lg:hidden">
    <label class="sr-only" for="server-workspace-mobile-nav">{{ __('Section') }}</label>
    <select
        id="server-workspace-mobile-nav"
        class="block w-full rounded-xl border border-brand-ink/15 bg-white px-3 py-2.5 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:outline-none focus:ring-2 focus:ring-brand-sage/30"
        onchange="if (this.value && window.Livewire) { window.Livewire.navigate(this.value); }"
    >
        @foreach (server_workspace_nav_for_server($server) as $item)
            @if (! empty($item['tabs']))
                {{-- Collapsed cluster (Access, Network, …): expand its member pages
                     so every destination stays reachable on mobile. --}}
                @foreach ($item['tabs'] as $tab)
                    <option
                        value="{{ $tab['url'] }}"
                        @selected($active === $tab['key'])
                    >{{ __($item['label']) }} › {{ $tab['label'] }}@if (! empty($tab['preview_only']) || ! empty($tab['soon_badge'])) — {{ __('coming soon') }}@endif @if (! empty($tab['needs_setup'])) &nbsp;•@endif</option>
                @endforeach
            @else
                <option
                    value="{{ server_workspace_nav_item_url($server, $item) }}"
                    @selected($active === $item['key'])
                >{{ __($item['label']) }}@if (! empty($item['preview_only']) || ! empty($item['soon_badge'])) — {{ __('coming soon') }}@endif @if (! empty($item['needs_setup'])) &nbsp;•@endif</option>
            @endif
        @endforeach
    </select>
</div>
