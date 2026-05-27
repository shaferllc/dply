
            @if ($engine_subtab === 'info')
                {{-- Engine info card — license, maintainer, wire protocol, best-for,
                     homepage + docs links. Reuses the partial built for caches so the
                     visual treatment stays consistent across workspaces. --}}
                @php $engineInfo = \App\Support\Servers\WebserverEngineInfo::for($key); @endphp
                @include('livewire.servers.partials.cache-engine-info-card', [
                    'info' => $engineInfo,
                    'row' => $isActive ? true : null,
                    'card' => $card,
                ])
            @endif
