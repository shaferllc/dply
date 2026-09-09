<?php

return [
    'kubectl_bin' => env('DPLY_KUBECTL_BIN', 'kubectl'),
    'context' => env('DPLY_KUBERNETES_CONTEXT'),
    'kubeconfig_path' => env('DPLY_KUBECONFIG_PATH'),
    'command_timeout_seconds' => (int) env('DPLY_KUBERNETES_COMMAND_TIMEOUT_SECONDS', 300),
    'rollout_timeout_seconds' => (int) env('DPLY_KUBERNETES_ROLLOUT_TIMEOUT_SECONDS', 180),
];
