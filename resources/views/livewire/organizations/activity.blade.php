<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="activity">
            <div class="space-y-8">
                <div class="dply-card overflow-hidden">
                    <div class="p-6 sm:p-8">
                        <div class="max-w-3xl">
                            <h2 class="text-lg font-semibold text-brand-ink">{{ __('Activity') }}</h2>
                            <p class="mt-2 text-sm text-brand-moss leading-relaxed">
                                {{ __('Recent audit events for this organization. Admins can review who did what and when.') }}
                            </p>
                        </div>
                    </div>
                </div>

                <div class="dply-card overflow-hidden">
                    <div class="p-6 sm:p-8">
                        @if ($this->auditLogs->isEmpty())
                            <p class="text-center text-sm text-brand-moss py-8">{{ __('No activity yet.') }}</p>
                        @else
                            <div class="overflow-x-auto rounded-xl border border-brand-mist">
                                <table class="min-w-full divide-y divide-brand-mist/80 text-sm">
                                    <thead>
                                        <tr class="bg-brand-sand/20 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                            <th scope="col" class="px-4 py-3">{{ __('Date') }}</th>
                                            <th scope="col" class="px-4 py-3">{{ __('User') }}</th>
                                            <th scope="col" class="px-4 py-3">{{ __('Action') }}</th>
                                            <th scope="col" class="px-4 py-3">{{ __('Subject') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-brand-mist/80 bg-white">
                                        @foreach ($this->auditLogs as $log)
                                            <tr>
                                                <td class="px-4 py-3 text-brand-moss whitespace-nowrap">{{ $log->created_at->format('M j, Y g:i A') }}</td>
                                                <td class="px-4 py-3 text-brand-ink">{{ $log->user?->name ?? '—' }}</td>
                                                <td class="px-4 py-3 text-brand-ink">{{ $log->action }}</td>
                                                <td class="px-4 py-3 text-brand-moss">{{ $log->subject_summary ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </x-organization-shell>
    </div>
</div>
