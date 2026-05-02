@props([
    'binding',
    'configuredClass' => 'bg-sky-100 text-sky-800',
])

@php
    $reason = $binding['config']['reason'] ?? null;
    $bindingNote = match ($reason) {
        'drivers_reference_redis_without_connection' => __('Session, cache, or queue targets Redis, but REDIS_HOST or REDIS_URL is not set.'),
        's3_disk_without_bucket' => __('S3-style disk is selected, but AWS_BUCKET or AWS_URL is missing.'),
        'bucket_without_keys' => __('Object storage bucket is set, but AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY is incomplete.'),
        'incomplete_object_storage_env' => __('Object storage environment looks incomplete.'),
        default => null,
    };
    $driver = $binding['config']['driver'] ?? null;
@endphp

<div class="flex items-start justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2">
    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium text-slate-900">{{ str($binding['type'] ?? 'resource')->headline() }}</p>
        <p class="text-xs text-slate-500">{{ str_replace('_', ' ', (string) ($binding['mode'] ?? 'attach_existing')) }}</p>
        @if ($bindingNote)
            <p class="mt-1 text-xs text-amber-800">{{ $bindingNote }}</p>
        @endif
        @if (($binding['type'] ?? '') === 'queue' && is_string($driver) && $driver !== '')
            <p class="mt-1 text-xs text-slate-500">{{ __('Queue driver: :driver', ['driver' => $driver]) }}</p>
        @endif
    </div>
    <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold {{ ($binding['status'] ?? 'pending') === 'configured' ? $configuredClass : 'bg-slate-100 text-slate-700' }}">
        {{ str($binding['status'] ?? 'pending')->headline() }}
    </span>
</div>
