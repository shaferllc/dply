{{-- SSH key reminder --}}
@if (! $serverHasPersonalProfileKey)
    {{-- Amber-toned dense head. The "Access" eyebrow is dropped: the key icon
         and the amber tint already say which register this is, and the headline
         repeats it. Body prose keeps its own row — it's the one thing here worth
         reading — but at text-xs like every other explanatory line on Overview. --}}
    <section class="dply-card overflow-hidden border-amber-200 p-0">
        <x-workspace-panel-head
            dense
            tone="amber"
            icon="heroicon-o-key"
            :title="__('Add your personal SSH key before you need this server')"
            class="border-b border-brand-ink/10"
        >
            <x-slot:actions>
                @if (! $hasProfileSshKeys)
                    <a href="{{ route('profile.ssh-keys') }}" wire:navigate class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-lg bg-brand-ink px-2.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                        <x-heroicon-m-plus class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Add a profile key') }}
                    </a>
                @endif
                <x-outline-link href="{{ route('servers.ssh-keys', $server) }}" wire:navigate size="xxs">
                    {{ __('Open SSH keys workspace') }}
                </x-outline-link>
            </x-slot:actions>
        </x-workspace-panel-head>
        <p class="bg-amber-50/60 px-3 py-2 text-xs leading-relaxed text-brand-moss sm:px-4">
            @if ($hasProfileSshKeys)
                {{ __('This server is ready, but it does not yet include one of your personal profile SSH keys. Attach one from the SSH keys workspace and sync authorized_keys so your own login access is on the machine.') }}
            @else
                {{ __('This server is ready, but you do not have any personal SSH keys saved in your profile yet. Add one first, then attach it from the SSH keys workspace so your own login access is on the machine.') }}
            @endif
        </p>
    </section>
@endif
