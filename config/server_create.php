<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Server-create wizard — what's on offer
|--------------------------------------------------------------------------
|
| Temporary switches for paths that are BUILT but not being offered yet. Each
| one still renders in the UI as a disabled "Coming soon" tile, so the roadmap
| stays visible, and each is refused server-side as well — the markup is never
| the only gate.
|
| To bring something back, add it here. Nothing else needs touching: the flows
| behind these switches (import/scan, Docker hosts, Kubernetes registration,
| the other stack templates) are all intact.
|
*/

return [
    /*
     * Stack templates offered on Step 3, by id from ServerCreatePresetCatalog:
     * laravel, rails, nextjs, django, polyglot, wordpress, static, database,
     * custom.
     */
    'available_presets' => ['laravel', 'wordpress'],

    /*
     * Step 1 modes. 'provider' and 'custom' are always on; this switches the
     * third tile, "Scan & import existing", which reads machines from a
     * provider API (StepScan).
     */
    'import_mode_enabled' => false,

    /*
     * Step 2 host kinds for provider mode. Of: vm, docker, kubernetes.
     */
    'available_provider_host_kinds' => ['vm'],
];
