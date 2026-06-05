@if (! $featureActive)
    <x-coming-soon-panel
        icon="heroicon-o-signal"
        :title="__('Realtime')"
        :description="__('A managed Pusher-compatible WebSocket relay built on Cloudflare Workers and DigitalOcean. Drop-in for Laravel Echo and pusher-js — connect with your credentials, no relay server to run.')"
        :points="[
            __('Pusher-compatible channels — works with existing Laravel Echo and pusher-js setups'),
            __('Managed infrastructure on Cloudflare Workers and DigitalOcean'),
            __('Billed flat per app through dply — no separate Pusher account needed'),
            __('Credentials and endpoint served from your dply workspace'),
        ]"
    />
@else
    @php
        $statusTone = [
            \App\Models\RealtimeApp::STATUS_ACTIVE       => 'success',
            \App\Models\RealtimeApp::STATUS_PROVISIONING => 'warning',
            \App\Models\RealtimeApp::STATUS_FAILED       => 'danger',
            \App\Models\RealtimeApp::STATUS_PAUSED       => 'neutral',
        ];
    @endphp

    <div class="space-y-8">
        <x-page-header
            eyebrow="Realtime"
            title="Realtime apps"
            description="Managed Pusher/Reverb-compatible channels on dply's edge. Drop-in for laravel-echo and pusher-js — connect with credentials, no server to run."
            :toolbar="true"
        >
            <x-slot name="actions">
                <a href="{{ route('realtime.create') }}" wire:navigate>
                    <x-primary-button type="button">New realtime app</x-primary-button>
                </a>
            </x-slot>
        </x-page-header>

        <x-table-card title="Your apps" subtitle="Billed ${{ number_format($priceCents / 100, 2) }} / app / month while active.">
            @if ($apps->isEmpty())
                <x-table-card-empty>
                    <p class="font-medium text-brand-ink">No realtime apps yet</p>
                    <p class="mt-1">Create one to get a WebSocket endpoint and credentials.</p>
                    <a href="{{ route('realtime.create') }}" wire:navigate class="mt-4 inline-block">
                        <x-primary-button type="button">New realtime app</x-primary-button>
                    </a>
                </x-table-card-empty>
            @else
                <div class="overflow-hidden rounded-xl border border-brand-ink/10">
                    <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                        <thead class="bg-brand-sand/20 text-left text-xs uppercase tracking-wide text-brand-moss">
                            <tr>
                                <th class="px-4 py-3 font-semibold">Name</th>
                                <th class="px-4 py-3 font-semibold">Status</th>
                                <th class="px-4 py-3 font-semibold">Endpoint</th>
                                <th class="px-4 py-3 font-semibold">Created</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-brand-ink/5 bg-white">
                            @foreach ($apps as $app)
                                <tr class="cursor-pointer hover:bg-brand-sand/10" onclick="window.location='{{ route('realtime.show', $app) }}'">
                                    <td class="px-4 py-3 font-medium text-brand-ink">{{ $app->name }}</td>
                                    <td class="px-4 py-3">
                                        <x-badge :tone="$statusTone[$app->status] ?? 'neutral'" size="sm">{{ ucfirst($app->status) }}</x-badge>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-brand-moss">{{ $app->host() }}</td>
                                    <td class="px-4 py-3 text-brand-moss">{{ $app->created_at?->diffForHumans() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-table-card>
    </div>
@endif
