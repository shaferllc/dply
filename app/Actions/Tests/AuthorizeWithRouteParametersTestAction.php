<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use App\Actions\ActionRequest;
use App\Actions\Actions;
use App\Actions\Concerns\AsController;

class AuthorizeWithRouteParametersTestAction extends Actions
{
    use AsController;

    public function authorize(string $someRouteParameter): bool
    {
        return $someRouteParameter !== 'unauthorized';
    }

    public function handle(ActionRequest $request, string $someRouteParameter): array
    {
        return [$someRouteParameter];
    }
}
