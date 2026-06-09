                <section class="dply-card overflow-hidden p-6 sm:p-8">
                    <livewire:sites.site-log-viewer :server="$server" :site="$site" wire:key="site-log-show-{{ $site->id }}" />
                </section>
