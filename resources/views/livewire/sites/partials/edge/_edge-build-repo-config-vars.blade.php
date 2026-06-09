@php
    use App\Models\EdgeDeployment;

    $edgeBuildRepoConfig = null;
    if ($site->relationLoaded('edgeDeployments') && $site->edgeDeployments !== null) {
        $deploymentsWithConfig = $site->edgeDeployments->filter(
            fn (EdgeDeployment $deployment): bool => is_array($deployment->repo_config) && $deployment->repo_config !== [],
        );
        $edgeBuildRepoConfig = $deploymentsWithConfig
            ->first(fn (EdgeDeployment $deployment): bool => $deployment->status === EdgeDeployment::STATUS_LIVE)
            ?->repo_config
            ?? $deploymentsWithConfig->first()?->repo_config;
    }
@endphp

