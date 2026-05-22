<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Deploy\DeploymentContractBuilder;

class SiteDotEnvComposer
{
    public function __construct(
        private readonly DeploymentContractBuilder $contractBuilder,
        private readonly DotEnvFileWriter $writer,
    ) {}

    /**
     * @return array<string, string>
     */
    public function composeMap(Site $site): array
    {
        return $this->contractBuilder->build($site)->environmentMap();
    }

    public function compose(Site $site): string
    {
        return $this->writer->render($this->composeMap($site));
    }
}
