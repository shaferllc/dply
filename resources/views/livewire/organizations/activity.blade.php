<div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <x-organization-shell :organization="$organization" section="activity">
            <x-breadcrumb-trail :items="[
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => $organization->name, 'href' => route('organizations.show', $organization), 'icon' => 'building-office-2'],
                ['label' => __('Activity'), 'icon' => 'archive-box'],
            ]" />

            <x-page-header
                :title="__('Activity')"
                :description="__('Recent audit events for this organization. Admins can review who did what and when.')"
                doc-route="docs.index"
                toolbar
            />

            <div class="dply-card overflow-hidden">
                @if ($this->auditLogs->isEmpty())
                    <p class="px-6 py-12 text-center text-sm text-brand-moss">{{ __('No activity yet.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-brand-mist/80 text-sm">
                            <thead>
                                <tr class="bg-brand-sand/20 text-left text-xs font-semibold uppercase tracking-wide text-brand-moss">
                                    <th scope="col" class="px-4 py-2.5 w-44">{{ __('When') }}</th>
                                    <th scope="col" class="px-4 py-2.5 w-44">{{ __('User') }}</th>
                                    <th scope="col" class="px-4 py-2.5 w-56">{{ __('Action') }}</th>
                                    <th scope="col" class="px-4 py-2.5">{{ __('Subject') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-brand-mist/60 bg-white">
                                @foreach ($this->auditLogs as $log)
                                    <tr class="hover:bg-brand-sand/15">
                                        <td class="px-4 py-2 whitespace-nowrap text-brand-moss tabular-nums" title="{{ $log->created_at->toDayDateTimeString() }}">
                                            <div>{{ $log->created_at->format('M j, Y') }}</div>
                                            <div class="text-xs text-brand-mist">{{ $log->created_at->format('g:i A') }}</div>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-brand-ink">{{ $log->user?->name ?? '—' }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-brand-ink">
                                            <code class="font-mono text-[12.5px] text-brand-ink">{{ $log->action }}</code>
                                        </td>
                                        <td class="px-4 py-2 text-brand-moss"><span class="break-all">{{ $log->subject_summary ?? '—' }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-organization-shell>
    </div>
</div>
