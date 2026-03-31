@props(['finding'])

@php
    $c = $finding->correlation;
@endphp
@if (is_array($c) && ! empty($c['type']))
    <p class="mt-2 text-xs text-brand-moss">
        @if ($c['type'] === 'site_deployment')
            {{ __('Possibly related: successful deploy :sha', ['sha' => \Illuminate\Support\Str::limit((string) ($c['git_sha'] ?? __('unknown')), 14)]) }}
        @elseif ($c['type'] === 'firewall_apply')
            {{ __('Possibly related: firewall apply.') }}
        @elseif ($c['type'] === 'cron_job_run')
            {{ __('Possibly related: scheduled task run.') }}
        @elseif ($c['type'] === 'task_runner')
            {{ __('Possibly related: remote task “:name”.', ['name' => \Illuminate\Support\Str::limit((string) ($c['name'] ?? ''), 80)]) }}
        @else
            {{ __('Possibly related: recent change on this server.') }}
        @endif
    </p>
@endif
