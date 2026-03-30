<?php

return [

    /*
    | Available placeholders in template content (replaced when applying or testing).
    */
    'placeholders' => [
        'DOMAIN' => 'Primary hostname for the site (e.g. app.example.com).',
        'SYSTEM_USER' => 'Unix user that owns the site files (isolation user or deploy user).',
        'DIRECTORY' => 'Path to the web root relative to the home layout (often /public).',
        'SOCKET' => 'PHP-FPM socket path for this site’s PHP version.',
    ],

    /*
    | If present, template must include this exact line (after substitution is not checked — the raw template).
    */
    'required_banner_line' => '# Dply webserver template — do not remove',

];
