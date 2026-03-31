<?php

namespace App\Services\Servers;

class CaddySiteConfigBuilder
{
    public function build(string $documentRoot, ?string $phpSocket = null): string
    {
        $documentRoot = trim($documentRoot) !== '' ? trim($documentRoot) : '/var/www/html';

        if ($phpSocket) {
            return <<<CADDY
:80 {
    root * {$documentRoot}
    php_fastcgi unix//{$phpSocket}
    encode zstd gzip
    file_server
}
CADDY;
        }

        return <<<CADDY
:80 {
    root * {$documentRoot}
    encode zstd gzip
    file_server
}
CADDY;
    }
}
