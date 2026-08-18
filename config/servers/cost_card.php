<?php

return [

    /*
    | Guest metrics older than this are flagged as stale on the cost card.
    */
    'metrics_stale_hours' => max(1, (int) env('DPLY_SERVER_COST_METRICS_STALE_HOURS', 24)),

    /*
    | Right-size nudge thresholds — conservative heuristics from guest metrics.
    */
    'right_size' => [
        'low_util_pct' => (float) env('DPLY_SERVER_COST_LOW_UTIL_PCT', 15),
        'headroom_util_pct' => (float) env('DPLY_SERVER_COST_HEADROOM_UTIL_PCT', 40),
        'hot_util_pct' => (float) env('DPLY_SERVER_COST_HOT_UTIL_PCT', 85),
        /*
        | A host only counts as "oversized" once it is big enough that
        | downsizing would save something. These replace the old XS-XL tier
        | weight gate and are set to the same cut-off it encoded (tier M).
        */
        'oversized_min_vcpu' => max(1, (int) env('DPLY_SERVER_COST_OVERSIZED_MIN_VCPU', 3)),
        'oversized_min_mem_mb' => max(1, (int) env('DPLY_SERVER_COST_OVERSIZED_MIN_MEM_MB', 4096)),
        'min_per_site_pct' => (float) env('DPLY_SERVER_COST_MIN_PER_SITE_PCT', 5),
    ],

];
