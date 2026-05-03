<?php

/**
 * Feature flags for the /launches/* lanes.
 *
 * `local_docker_enabled` controls whether the Containers launcher exposes
 * the local_orbstack_docker / local_orbstack_kubernetes target options. The
 * defaults route through env(): present in local env (so dogfooding works
 * without extra setup), absent in production (so customers don't see a
 * 127.0.0.1 / dplytest@:2222 option that only points at the dply machine).
 */
return [
    'local_docker_enabled' => env('DPLY_ENABLE_LOCAL_DOCKER_LAUNCH'),
];
