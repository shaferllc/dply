<?php

namespace App\Services\Servers;

use App\Models\Server;

class ServerDeployLayoutBuilder
{
    /**
     * @return array{root:string,app:string,releases:string,current:string,shared:string,logs:string,tmp:string,bin:string}
     */
    public function build(Server $server): array
    {
        $slug = preg_replace('/[^a-z0-9\-]+/i', '-', strtolower($server->name ?: 'server'));
        $slug = trim((string) $slug, '-');
        $slug = $slug !== '' ? $slug : 'server';

        $root = "/home/dply/apps/{$slug}";

        return [
            'root' => $root,
            'app' => "{$root}/app",
            'releases' => "{$root}/releases",
            'current' => "{$root}/current",
            'shared' => "{$root}/shared",
            'logs' => "{$root}/logs",
            'tmp' => "{$root}/tmp",
            'bin' => '/usr/local/bin',
        ];
    }
}
