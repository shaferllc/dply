<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Banner stale threshold
    |--------------------------------------------------------------------------
    |
    | A queued/running run older than this is treated as stuck — the workspace
    | auto-flips it to `failed` on the next poll so the operator can dismiss
    | and retry. Must be ≥ the longest legitimate fix run; reaping early
    | would kill a healthy in-flight apt-security-updates job (handler runs
    | up to 600s on busy boxes; ApplyInsightFixJob $timeout = 700s; Horizon
    | supervisor timeout defaults to 720s). Override per environment when
    | you know your fixes finish faster.
    |
    */
    'stale_threshold_seconds' => (int) env('INSIGHTS_WORKSPACE_STALE_THRESHOLD', 720),

    /*
    |--------------------------------------------------------------------------
    | Banner output buffer cap
    |--------------------------------------------------------------------------
    |
    | Soft cap on lines kept in the cache buffer. The UI shows the tail.
    |
    */
    'max_buffer_lines' => 500,

    /*
    |--------------------------------------------------------------------------
    | Run-checks (full sweep / single recheck) banner state
    |--------------------------------------------------------------------------
    |
    | Driven by RunServerInsightsJob / RunSiteInsightsJob. Server-scoped runs
    | write to server.meta; site-scoped runs write to site.meta. Streaming
    | output buffer lives in the application cache keyed by run_id with a
    | TTL ~5 minutes after completion.
    |
    */
    'meta_run_run_id_key' => 'insights_run_run_id',
    'meta_run_status_key' => 'insights_run_status',
    'meta_run_started_at_key' => 'insights_run_started_at',
    'meta_run_finished_at_key' => 'insights_run_finished_at',
    'meta_run_error_key' => 'insights_run_error',
    'run_output_cache_key_prefix' => 'insights_run_output:',
    'run_output_cache_ttl_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Apply-fix banner state
    |--------------------------------------------------------------------------
    |
    | Driven by ApplyInsightFixJob. Server-scoped findings write banner state
    | to server.meta; site-scoped findings write to site.meta. The job ALSO
    | continues to write its existing terminal keys to InsightFinding.meta —
    | the modal pill reads from there; the banner reads from server/site meta.
    |
    | meta_fix_finding_id_key tracks which finding the current banner refers
    | to so the workspace can label the banner ("Apply fix to X").
    |
    */
    'meta_fix_run_id_key' => 'insights_fix_run_id',
    'meta_fix_finding_id_key' => 'insights_fix_finding_id',
    'meta_fix_status_key' => 'insights_fix_status',
    'meta_fix_started_at_key' => 'insights_fix_started_at',
    'meta_fix_finished_at_key' => 'insights_fix_finished_at',
    'meta_fix_error_key' => 'insights_fix_error',
    'fix_output_cache_key_prefix' => 'insights_fix_output:',
    'fix_output_cache_ttl_seconds' => 300,

    /*
    |--------------------------------------------------------------------------
    | Revert-fix banner state
    |--------------------------------------------------------------------------
    |
    | Driven by RevertInsightFixJob. Same shape as apply-fix.
    |
    */
    'meta_revert_run_id_key' => 'insights_revert_run_id',
    'meta_revert_finding_id_key' => 'insights_revert_finding_id',
    'meta_revert_status_key' => 'insights_revert_status',
    'meta_revert_started_at_key' => 'insights_revert_started_at',
    'meta_revert_finished_at_key' => 'insights_revert_finished_at',
    'meta_revert_error_key' => 'insights_revert_error',
    'revert_output_cache_key_prefix' => 'insights_revert_output:',
    'revert_output_cache_ttl_seconds' => 300,

];
