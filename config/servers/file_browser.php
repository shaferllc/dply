<?php

/**
 * File browser limits, sensitive-path detection, and server-browser quick-jumps.
 */
return [

    /**
     * Maximum entries returned from a single directory listing. Above this, the
     * UI surfaces a glob filter so the operator can narrow the result rather
     * than streaming a 50k-entry table.
     */
    'listing_entry_cap' => (int) env('SERVER_FILE_BROWSER_LISTING_CAP', 2000),

    /**
     * Inline editor (CodeMirror) hard cap. Files larger than this are
     * download-only.
     */
    'edit_max_bytes' => (int) env('SERVER_FILE_BROWSER_EDIT_MAX_BYTES', 1_048_576),

    /**
     * Direct-download streaming cap. Files larger than this require Manage → Run.
     */
    'download_max_bytes' => (int) env('SERVER_FILE_BROWSER_DOWNLOAD_MAX_BYTES', 26_214_400),

    /**
     * SSH timeout (seconds) for a single file-browser command (ls, stat, cat).
     */
    'ssh_timeout_seconds' => (int) env('SERVER_FILE_BROWSER_SSH_TIMEOUT', 30),

    /**
     * Opens of paths matching any of these glob-style patterns are recorded
     * to the org activity log (writes/downloads/escalations are always logged
     * regardless). Patterns use fnmatch() semantics.
     */
    'sensitive_path_globs' => [
        '*.env',
        '*.env.*',
        '*.pem',
        '*.key',
        '*id_rsa*',
        '*id_ed25519*',
        '*id_ecdsa*',
        '/etc/shadow',
        '/etc/gshadow',
        '/etc/sudoers',
        '/etc/sudoers.d/*',
        '/root/.ssh/*',
        '/home/*/.ssh/id_*',
        '/home/*/.aws/credentials',
        '/home/*/.kube/config',
    ],

    /**
     * Quick-jump shortcuts surfaced at the top of the server browser.
     */
    'server_quick_jumps' => [
        '/etc',
        '/var/log',
        '/var/www',
    ],
];
