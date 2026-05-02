<?php

namespace App\Services\Servers\Bootstrap;

use App\Models\Server;

interface ServerBootstrapStrategy
{
    public function supports(Server $server): bool;

    /**
     * @return list<string>
     */
    public function build(Server $server): array;

    /**
     * @return list<array{type:string,key:string,label:string,content:string,metadata:array<string,mixed>}>
     */
    public function buildArtifacts(Server $server): array;
}
