<?php

use App\Modules\TaskRunner\TaskServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FeatureServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    TaskServiceProvider::class,
    AppServiceProvider::class,
    FeatureServiceProvider::class,
    HorizonServiceProvider::class,
];
