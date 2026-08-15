{{--
    Both workspace rows, stacked: compute on top, services beneath.

    Rendering only the row you are "in" was rejected — Realtime would then be
    unreachable from /sites, which is the same discoverability failure that
    burying it in org settings caused. See docs/adr/managed-services-tier.md,
    decision 1.
--}}

<x-compute-index-nav />
<x-services-index-nav />
