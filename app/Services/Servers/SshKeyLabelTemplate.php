<?php

namespace App\Services\Servers;

use App\Models\Server;

class SshKeyLabelTemplate
{
    public static function resolveTemplate(Server $server): string
    {
        $srvKey = config('server_ssh_keys.meta_label_template_key');
        $fromServer = data_get($server->meta, $srvKey);
        if (is_string($fromServer) && trim($fromServer) !== '') {
            return trim($fromServer);
        }

        $prefKey = config('server_ssh_keys.org_site_preferences_label_template_key');
        $server->loadMissing('organization');
        $org = $server->organization;
        if ($org !== null) {
            $fromOrg = data_get($org->server_site_preferences, $prefKey);
            if (is_string($fromOrg) && trim($fromOrg) !== '') {
                return trim($fromOrg);
            }
        }

        return '{name}';
    }

    public static function apply(string $template, string $name, string $linuxUser, Server $server): string
    {
        $hostname = $server->name ?? '';

        return str_replace(
            ['{name}', '{user}', '{hostname}', '{date}'],
            [$name, $linuxUser, $hostname, now()->toDateString()],
            $template
        );
    }
}
