<?php

declare(strict_types=1);

return [

    'statuses' => [
        'planned' => 'Planned',
        'in_progress' => 'In progress',
        'shipped' => 'Shipped',
    ],

    'areas' => [
        'platform' => 'Platform',
        'servers' => 'Servers',
        'edge' => 'Edge',
        'cloud' => 'Cloud',
        'serverless' => 'Serverless',
        'other' => 'Other',
    ],

    'suggestion_statuses' => [
        'new' => 'New',
        'reviewed' => 'Reviewed',
        'declined' => 'Declined',
    ],

    'suggestion_rate_limit' => [
        'max_attempts' => (int) env('ROADMAP_SUGGESTION_MAX_ATTEMPTS', 3),
        'decay_seconds' => (int) env('ROADMAP_SUGGESTION_DECAY_SECONDS', 3600),
    ],

    'suggestion_emails_enabled' => filter_var(env('ROADMAP_SUGGESTION_EMAILS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'recently_shipped_limit' => (int) env('ROADMAP_RECENTLY_SHIPPED_LIMIT', 5),

    /*
     |--------------------------------------------------------------------------
     | AI auto-update
     |--------------------------------------------------------------------------
     |
     | The post-deploy AI updater reads recent git history, the user-suggestion
     | inbox, the docs/*roadmap*.md files, and the existing roadmap items, then
     | asks the configured LLM (see config/dply_ai.php) to flip shipped items,
     | draft new ones, triage suggestions, and refresh summaries.
     |
     | It is OFF by default — the whole pipeline no-ops unless `enabled` is true
     | AND the LLM is configured. `auto_publish` controls whether the AI's
     | changes go live immediately (true, the chosen behaviour) or land as
     | unpublished drafts an admin must publish (false).
     |
     */
    'ai' => [
        'enabled' => filter_var(env('ROADMAP_AI_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'auto_publish' => filter_var(env('ROADMAP_AI_AUTO_PUBLISH', true), FILTER_VALIDATE_BOOLEAN),

        // Explicit git directory to read history from. Null = auto-detect:
        // a local checkout's .git, else the atomic-release bare repo at
        // <root>/repo (two levels above base_path on a deployed box).
        'git_dir' => env('ROADMAP_AI_GIT_DIR'),

        // Caps to bound prompt size and blast radius per run.
        'max_commits' => (int) env('ROADMAP_AI_MAX_COMMITS', 200),
        'max_new_items' => (int) env('ROADMAP_AI_MAX_NEW_ITEMS', 8),
        'max_doc_chars' => (int) env('ROADMAP_AI_MAX_DOC_CHARS', 12000),
    ],

];
