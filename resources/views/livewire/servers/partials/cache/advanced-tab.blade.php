            <div class="{{ $card }} p-6 sm:p-8">
                <h2 class="text-base font-semibold text-brand-ink">{{ __('Audit log') }}</h2>
                <p class="mt-2 text-sm text-brand-moss">{{ __('Recent install / uninstall / restart / stop / start / flush events on cache services for this server.') }}</p>
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
