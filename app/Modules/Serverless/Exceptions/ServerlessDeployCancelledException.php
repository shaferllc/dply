<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Exceptions;

use RuntimeException;

/**
 * Thrown from a serverless deploy step checkpoint when the operator has
 * requested cancellation. {@see App\Modules\Deploy\Jobs\RunSiteDeploymentJob} catches it
 * like any deploy failure and records the deployment as failed.
 */
class ServerlessDeployCancelledException extends RuntimeException {}
