<?php

namespace App\Services\Servers;

class SystemdUnitFileBuilder
{
    public function buildDeployPrepareUnit(): string
    {
        return <<<'UNIT'
[Unit]
Description=Dply deploy layout preparation
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/dply-prepare-layout
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
UNIT;
    }
}
