{{-- SSH key reminder --}}
@if (! $serverHasPersonalProfileKey)
    <section class="dply-card overflow-hidden border-amber-200">
        <div class="border-b border-brand-ink/10 bg-amber-50/60 px-6 py-5 sm:px-7">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-3">
                    <x-icon-badge tone="amber">
                        <x-heroicon-o-key class="h-5 w-5" aria-hidden="true" />
                    </x-icon-badge>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-800">{{ __('Access') }}</p>
                        <h3 class="mt-0.5 text-base font-semibold text-brand-ink">{{ __('Add your personal SSH key before you need this server') }}</h3>
                        <p class="mt-1 max-w-2xl text-sm leading-relaxed text-brand-moss">
                            @if ($hasProfileSshKeys)
                                {{ __('This server is ready, but it does not yet include one of your personal profile SSH keys. Attach one from the SSH keys workspace and sync authorized_keys so your own login access is on the machine.') }}
                            @else
                                {{ __('This server is ready, but you do not have any personal SSH keys saved in your profile yet. Add one first, then attach it from the SSH keys workspace so your own login access is on the machine.') }}
                            @endif
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    @if (! $hasProfileSshKeys)
                        <a href="{{ route('profile.ssh-keys') }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest">
                            <x-heroicon-m-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                            {{ __('Add a profile key') }}
                        </a>
                    @endif
                    <a href="{{ route('servers.ssh-keys', $server) }}" wire:navigate class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40">
                        {{ __('Open SSH keys workspace') }}
                    </a>
                </div>
            </div>
        </div>
    </section>
@endif
