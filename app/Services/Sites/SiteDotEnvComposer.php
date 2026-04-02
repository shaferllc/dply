<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\Deploy\DeploymentContractBuilder;

class SiteDotEnvComposer
{
    public function __construct(
        private readonly DeploymentContractBuilder $contractBuilder,
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
        $map = $this->composeMap($site);

        ksort($map);

        $lines = [];
        foreach ($map as $key => $value) {
            $lines[] = $key.'='.$this->escapeValue($value);
        }

        return implode("\n", $lines);
    }

    protected function escapeValue(string $value): string
    {
        if ($value === '') {
            return '""';
        }
        if (preg_match('/[\s"#$\\\\]/', $value)) {
            return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
        }

        return $value;
    }
}
