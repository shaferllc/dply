<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Contracts;

/**
 * A serverless backend that states which capabilities it implements.
 *
 * Implemented by provisioners (and by the backends behind
 * {@see \App\Modules\Serverless\Services\ServerlessBackendResolver}) so the
 * workspace can hide, disable, or explain a control instead of offering one
 * the provider will reject at deploy time.
 *
 * Use {@see \App\Modules\Serverless\Concerns\DeclaresFeatureSupport} for the
 * boilerplate — an implementer only lists its cases.
 */
interface DeclaresServerlessFeatures
{
    /** @return list<ServerlessFeature> */
    public function supportedServerlessFeatures(): array;

    public function supportsServerlessFeature(ServerlessFeature $feature): bool;
}
