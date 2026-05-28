<?php

/*
|--------------------------------------------------------------------------
| Serverless glue recipe catalog
|--------------------------------------------------------------------------
|
| Multi-engine orchestration patterns — OpenWhisk sequences wired to Edge
| deploy hooks, Cloud redeploy endpoints, and BYO cron callbacks. Recipes
| merge with org inventory in ServerlessGluePlanner.
|
*/

return [

    'edge_webhook_pipeline' => [
        'title' => 'Edge deploy hook → serverless sequence',
        'summary' => 'Mint an Edge deploy hook, chain serverless code actions in an OpenWhisk sequence, and point the hook at the sequence web action URL.',
        'doc_slug' => 'edge-deploy-triggers',
        'requires' => 'edge_hooks_and_actions',
        'steps' => [
            'Inventory Edge sites with deploy hooks and your DO Functions namespace code actions.',
            'Create a serverless sequence on the functions host that chains at least two code actions (validate → notify → redeploy, etc.).',
            'Deploy the sequence to OpenWhisk and copy its web-export invocation URL from the function Platform tab.',
            'On the Edge site, open Deploy triggers and mint a deploy hook — or call the hook URL from your sequence’s last action.',
            'Smoke-test: POST the Edge hook and confirm the sequence runs end-to-end in serverless invocation logs.',
            'Document the hook prefix and sequence name in your runbook for on-call.',
        ],
    ],

    'cloud_redeploy_chain' => [
        'title' => 'Serverless sequence → Cloud redeploy',
        'summary' => 'Chain code actions that call a Cloud app redeploy webhook or live URL after Edge or BYO events.',
        'doc_slug' => 'cloud-apps',
        'requires' => 'cloud_and_actions',
        'steps' => [
            'Pick the Cloud app that should redeploy when the glue sequence fires.',
            'Add a code action whose handler POSTs to `/hooks/cloud/{site}/redeploy` or your Cloud live URL health check.',
            'Define a sequence: upstream action (parse webhook payload) → Cloud redeploy action.',
            'Deploy the sequence and wire your trigger (Edge hook, cron trigger, or manual console invoke).',
            'Verify the Cloud deploy history shows a new release after a test invoke.',
        ],
    ],

    'byo_cron_callback' => [
        'title' => 'Sequence → BYO cron callback',
        'summary' => 'Finish an OpenWhisk sequence with a BYO server cron or HTTP callback — useful for VPS tasks Railway cannot reach.',
        'doc_slug' => 'server-cron-jobs',
        'requires' => 'byo_cron_and_actions',
        'steps' => [
            'Identify the BYO server cron or site endpoint the sequence should hit last.',
            'Author a code action that signs and POSTs to your BYO callback URL or internal API.',
            'Chain it after validation/normalization actions in a sequence on the functions host.',
            'Optionally mirror timing with an OpenWhisk cron trigger on the first action.',
            'Confirm the BYO cron log or site deploy hook receives the callback on test invoke.',
        ],
    ],

    'multi_engine_orchestration' => [
        'title' => 'Edge → serverless → Cloud → BYO',
        'summary' => 'Full-stack glue: Edge deploy hook triggers a sequence that redeploys Cloud and pings BYO cron — orchestration without leaving dply.',
        'doc_slug' => 'edge-delivery',
        'requires' => 'full_stack',
        'steps' => [
            'Map inventory: Edge hooks, functions namespace actions, Cloud apps, and BYO crons.',
            'Build a three-or-more-step sequence: parse Edge payload → Cloud redeploy → BYO callback (order as needed).',
            'Deploy the sequence; enable web-export so Edge hooks or external callers can reach step one.',
            'Mint or reuse an Edge deploy hook; document which sequence action each engine step targets.',
            'Run a dry-run invoke from the Platform console before attaching production hooks.',
            'Monitor Cloud deploy history, BYO cron output, and serverless invocation logs after cutover.',
        ],
    ],

];
