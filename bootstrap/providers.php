<?php

use App\Modules\Feedback\FeedbackServiceProvider;
use App\Modules\Roadmap\RoadmapServiceProvider;
use App\Modules\TaskRunner\TaskServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\SecretVaultServiceProvider;

return [
    TaskServiceProvider::class,
    FeedbackServiceProvider::class,
    RoadmapServiceProvider::class,
    AppServiceProvider::class,
    FeatureServiceProvider::class,
    HorizonServiceProvider::class,
    SecretVaultServiceProvider::class,
];
