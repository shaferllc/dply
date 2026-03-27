<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class OperatorSummaryController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'app' => 'dply',
            'generated_at' => now()->toIso8601String(),
            'environment' => app()->environment(),
            'users' => User::query()->count(),
            'organizations' => Organization::query()->count(),
            'metrics' => [
                'servers' => Server::query()->count(),
                'sites' => Site::query()->count(),
                'site_deployments' => SiteDeployment::query()->count(),
            ],
        ]);
    }
}
