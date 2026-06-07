{{--
    Lazy-load skeleton for server workspace tabs. Rendered by
    App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder::placeholder()
    while a #[Lazy] workspace component hydrates. It re-renders the same
    <x-server-workspace-layout> chrome (header + active tab) as the real
    page so switching tabs via wire:navigate shows the destination tab
    highlighted instantly with a pulsing body, then fills in.

    Components with their own URL-navigated sub-tab strip (Settings, Manage)
    override placeholder() to use workspace-subtab-placeholder instead, so
    the sub-tabs stay visible too.

    Receives: $server, $active (tab key | null), $title (string | null).
--}}
<x-server-workspace-layout :server="$server" :active="$active" :title="$title">
    @include('livewire.servers.partials._skeleton-cards')
</x-server-workspace-layout>
