<?php

use App\Modules\Docs\DocsServiceProvider;
use App\Modules\Feedback\FeedbackServiceProvider;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Insights\InsightsServiceProvider;
use App\Modules\OpsCopilot\OpsCopilotServiceProvider;
use App\Modules\Referrals\ReferralsServiceProvider;
use App\Modules\Roadmap\RoadmapServiceProvider;
use App\Modules\TaskRunner\TaskServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\SecretVaultServiceProvider;

return [
    TaskServiceProvider::class,
    DocsServiceProvider::class,
    FeedbackServiceProvider::class,
    ImportsServiceProvider::class,
    InsightsServiceProvider::class,
    OpsCopilotServiceProvider::class,
    ReferralsServiceProvider::class,
    RoadmapServiceProvider::class,
    AppServiceProvider::class,
    FeatureServiceProvider::class,
    HorizonServiceProvider::class,
    SecretVaultServiceProvider::class,
];
