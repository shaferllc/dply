<?php

namespace App\Services\Sites\Contracts;

use App\Models\Site;

interface SiteWebserverProvisioner
{
    public function webserver(): string;

    public function provision(Site $site): string;

    public function remove(Site $site): string;
}
