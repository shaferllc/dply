            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-lg font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Recent install / uninstall / restart / stop / start / flush events on cache services for this server.') }}</p>
                <x-explainer class="mt-3">
                    <p>{{ __('Every operator action through this workspace writes a row here. Events are also forwarded to the organization-wide audit log when a signed-in user is the actor.') }}</p>
                    <p>{{ __('Most recent 40 events shown. Event names are stable identifiers (e.g. cache_service_restarted) so they\'re grep-able from the org log; the engine field tells you which cache the event acted on.') }}</p>
                </x-explainer>
                <ul class="mt-6 divide-y divide-brand-ink/10 text-sm">
                    @forelse ($cacheAuditEvents as $ev)
                        <li class="py-3">
                            <span class="font-medium text-brand-ink">{{ $ev->event }}</span>
                            <span class="text-brand-mist"> · </span>
                            <span class="text-brand-moss">{{ $ev->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span>
                            @if ($ev->user)
                                <span class="text-brand-mist"> · </span>
                                <span class="text-brand-moss">{{ $ev->user->name }}</span>
                            @endif
                            @if (filled($ev->meta) && is_array($ev->meta) && isset($ev->meta['engine']))
                                <span class="text-brand-mist"> · </span>
                                <span class="font-mono text-xs text-brand-moss">
                                    {{ $ev->meta['engine'] }}@if (! empty($ev->meta['name']) && $ev->meta['name'] !== \App\Models\ServerCacheService::DEFAULT_INSTANCE_NAME)<span class="text-brand-mist">/</span>{{ $ev->meta['name'] }}@endif
                                </span>
                            @endif
                        </li>
                    @empty
                        <li class="py-4 text-brand-moss">{{ __('No events yet.') }}</li>
                    @endforelse
                </ul>
            </div>
