<?php

namespace App\Services\Servers;

use App\Services\Servers\Concerns\BuildsPhpExtensionScripts;
use App\Services\Servers\Concerns\BuildsPhpScripts;
use App\Services\Servers\Concerns\BuildsPhpWorkspaceData;
use App\Services\Servers\Concerns\GuardsPhpExtensionActions;
use App\Services\Servers\Concerns\GuardsPhpPackageActions;
use App\Services\Servers\Concerns\ResolvesPhpInventory;
use App\Services\Servers\Concerns\RunsPhpExtensionActions;
use App\Services\Servers\Concerns\RunsPhpPackageActions;

class ServerPhpManager
{
    use BuildsPhpExtensionScripts;
    use BuildsPhpScripts;
    use BuildsPhpWorkspaceData;
    use GuardsPhpExtensionActions;
    use GuardsPhpPackageActions;
    use ResolvesPhpInventory;
    use RunsPhpExtensionActions;
    use RunsPhpPackageActions;
}
