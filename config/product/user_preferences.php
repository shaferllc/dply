<?php

return [

    /*
    |--------------------------------------------------------------------------
    | User (account) preferences
    |--------------------------------------------------------------------------
    | Stored on users.ui_preferences. Personal UX only — not shared with teams or orgs.
    */

    'defaults' => [
        'newsletter' => true,
        'keyboard_shortcuts' => true,
        'redirect_home_to_app' => true,
        'subscription_invoice_emails' => false,
        'theme' => 'system', // light | dark | system
        'navigation_layout' => 'sidebar', // sidebar | top
        'notification_position' => 'top_center',
    ],

    /*
    |--------------------------------------------------------------------------
    | Organization preferences
    |--------------------------------------------------------------------------
    | Stored on organizations.server_site_preferences. Org-wide policies (billing / infra).
    */

    'organization_server_site_defaults' => [
        'email_server_passwords' => true,
        'set_timezone_on_new_servers' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Team preferences
    |--------------------------------------------------------------------------
    | Stored on teams.preferences. Defaults for how servers/sites are listed and created for that team.
    */

    'team_server_site_defaults' => [
        'show_server_updates_in_list' => false,
        'isolate_new_sites' => false,
        'default_server_sort' => 'created_at',
        'default_site_sort' => 'none',
    ],

    'theme_options' => ['light', 'dark', 'system'],

    'navigation_layout_options' => ['sidebar', 'top'],

    'notification_positions' => [
        'top_center' => 'Top center',
        'top_right' => 'Top right',
        'bottom_right' => 'Bottom right',
        'bottom_left' => 'Bottom left',
    ],

    'server_sort_options' => [
        'created_at' => 'Creation date',
        'name' => 'Name',
        'status' => 'Status',
    ],

    'site_sort_options' => [
        'none' => 'No sorting',
        'name' => 'Name',
        'created_at' => 'Creation date',
    ],

];
