<?php

use App\Modules\Backups\BackupsServiceProvider;
use App\Modules\Billing\BillingServiceProvider;
use App\Modules\Certificates\CertificatesServiceProvider;
use App\Modules\Cloud\CloudServiceProvider;
use App\Modules\Database\DatabaseServiceProvider;
use App\Modules\Logs\LogsServiceProvider;
use App\Modules\Snapshots\SnapshotsServiceProvider;
use App\Modules\Deploy\DeployServiceProvider;
use App\Modules\Blog\BlogServiceProvider;
use App\Modules\Docs\DocsServiceProvider;
use App\Modules\Edge\EdgeServiceProvider;
use App\Modules\Feedback\FeedbackServiceProvider;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Insights\InsightsServiceProvider;
use App\Modules\Launch\LaunchServiceProvider;
use App\Modules\Marketplace\MarketplaceServiceProvider;
use App\Modules\OpsCopilot\OpsCopilotServiceProvider;
use App\Modules\Projects\ProjectsServiceProvider;
use App\Modules\Realtime\RealtimeServiceProvider;
use App\Modules\Referrals\ReferralsServiceProvider;
use App\Modules\Roadmap\RoadmapServiceProvider;
use App\Modules\Serverless\ServerlessServiceProvider;
use App\Modules\TaskRunner\TaskServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BundleSsoServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\LookoutDebugPageServiceProvider;
use App\Modules\Secrets\SecretVaultServiceProvider;

return [
    TaskServiceProvider::class,
    BackupsServiceProvider::class,
    BillingServiceProvider::class,
    CertificatesServiceProvider::class,
    CloudServiceProvider::class,
    DatabaseServiceProvider::class,
    DeployServiceProvider::class,
    LogsServiceProvider::class,
    SnapshotsServiceProvider::class,
    BlogServiceProvider::class,
    DocsServiceProvider::class,
    EdgeServiceProvider::class,
    FeedbackServiceProvider::class,
    ImportsServiceProvider::class,
    InsightsServiceProvider::class,
    LaunchServiceProvider::class,
    MarketplaceServiceProvider::class,
    OpsCopilotServiceProvider::class,
    ProjectsServiceProvider::class,
    RealtimeServiceProvider::class,
    ReferralsServiceProvider::class,
    ServerlessServiceProvider::class,
    RoadmapServiceProvider::class,
    AppServiceProvider::class,
    BundleSsoServiceProvider::class,
    LookoutDebugPageServiceProvider::class,
    FeatureServiceProvider::class,
    HorizonServiceProvider::class,
    SecretVaultServiceProvider::class,
];
