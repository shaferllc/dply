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
        @foreach (config('server_workspace.nav', []) as $item)
            <option
                value="{{ server_workspace_nav_item_url($server, $item) }}"
                @selected($active === $item['key'])
            >{{ __($item['label']) }}</option>
        @endforeach
    </select>
</div>
