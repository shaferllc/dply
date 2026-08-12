<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Concerns;

use App\Modules\Serverless\Contracts\ServerlessFeature;

/**
 * Boilerplate for {@see \App\Modules\Serverless\Contracts\DeclaresServerlessFeatures}.
 *
 * An implementer overrides {@see serverlessFeatures()} with its cases; the
 * default is an empty set, so a backend that declares nothing is treated as
 * supporting nothing rather than everything.
 */
trait DeclaresFeatureSupport
{
    /** @return list<ServerlessFeature> */
    public function supportedServerlessFeatures(): array
    {
        return $this->serverlessFeatures();
    }

    public function supportsServerlessFeature(ServerlessFeature $feature): bool
    {
        return in_array($feature, $this->serverlessFeatures(), true);
    }

    /** @return list<ServerlessFeature> */
    protected function serverlessFeatures(): array
    {
        return [];
    }
}
