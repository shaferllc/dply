<?php

declare(strict_types=1);

return [

    /*
    | Deploy Contract — cross-engine promote gate (Edge v1).
    |
    | When enabled, production promote requires a passing contract run
    | (or an audited waiver) for the preview deployment being promoted.
    */

    'require_for_promote' => filter_var(
        env('DPLY_DEPLOY_CONTRACT_REQUIRE_PROMOTE', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'require_run_before_promote' => filter_var(
        env('DPLY_DEPLOY_CONTRACT_REQUIRE_RUN', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'allow_waivers' => filter_var(
        env('DPLY_DEPLOY_CONTRACT_ALLOW_WAIVERS', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    /*
    | Shadow replay check (only when global.edge_deploy_replay is on).
    */
    'require_replay_when_enabled' => filter_var(
        env('DPLY_DEPLOY_CONTRACT_REQUIRE_REPLAY', true),
        FILTER_VALIDATE_BOOLEAN,
    ),

    'min_replay_pass_rate' => max(0.0, min(100.0, (float) env('DPLY_DEPLOY_CONTRACT_MIN_REPLAY_PASS', 99.0))),

    /*
    | Repo contract files (loaded at Edge build into deployment repo_config):
    |   dply-contract.yaml — promote.requires, min_replay_pass_rate, require_replay
    |   dply.yaml `contract:` — same shape
    |
    | Check keys: edge.preview.build, edge.preview.review, edge.preview.replay,
    | edge.env.keys_subset, edge.origin.health, cloud.origin.health, byo.deploy.health
    */

];
