<?php

namespace App\Services\Servers;

use App\Services\Servers\Concerns\BuildsPhpScripts;
use App\Services\Servers\Concerns\BuildsPhpWorkspaceData;
use App\Services\Servers\Concerns\GuardsPhpPackageActions;
use App\Services\Servers\Concerns\ResolvesPhpInventory;
use App\Services\Servers\Concerns\RunsPhpPackageActions;

class ServerPhpManager
{
    use BuildsPhpScripts;
    use BuildsPhpWorkspaceData;
    use GuardsPhpPackageActions;
    use ResolvesPhpInventory;
    use RunsPhpPackageActions;
}
