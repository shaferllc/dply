<?php

namespace App\Actions\Concerns;

trait AsAction
{
    // use AsABTestable;
    use AsApiResponse;
    use AsApiVersion;
    use AsAuditable;
    use AsAuthenticated;
    use AsAuthorized;
    use AsBatch;
    use AsBroadcast;
    use AsBulk;
    use AsCachedResult;
    use AsCircuitBreaker;
    use AsCommand;
    use AsCompensatable;
    use AsConditional;
    use AsContext;
    use AsController;
    use AsCostTracked;
    use AsDebounced;
    use AsDebuggable;
    use AsDependent;
    use AsDeprecated;
    use AsDistributedLock;
    use AsDTO;
    use AsEncrypted;
    use AsEvent;
    use AsFactory;
    use AsFake;
    use AsFeatureFlagged;
    use AsFiltered;
    use AsHydratable;
    use AsIdempotent;
    use AsJob;
    use AsJWT;
    use AsLazy;
    use AsLifecycle;
    use AsListener;
    use AsLock;
    use AsLogger;
    use AsMacroable;
    use AsMail;
    use AsMetrics;
    use AsMiddleware;
    use AsNotification;
    use AsOAuth;
    use AsObject {
        AsObject::make as objectMake;
        AsFactory::make insteadof AsObject;
    }
    use AsObservable {
        AsObservable::observe as observeEvents;
    }
    use AsObserver {
        AsObserver::observe insteadof AsObservable;
    }
    use AsPaginated;
    use AsParallel {
        AsObject::run insteadof AsParallel;
        AsParallel::run as runParallel;
    }
    use AsPasswordConfirmation;
    use AsPermission;
    use AsPipeline;
    use AsProgressive;
    use AsQueueBatch;
    use AsRateLimiter;
    use AsRequiresBillingFeature;
    use AsRequiresCapability;
    use AsRequiresPlan;
    use AsRequiresRole;
    use AsRequiresSubscription;
    use AsResource {
        AsResource::toArray insteadof AsNotification;
        AsResource::toArray insteadof AsSerializable;
    }
    use AsResponse;
    use AsRetry;
    use AsReversible;
    use AsRule;
    use AsSanitizer;
    use AsSchedule;
    use AsSerializable {
        AsSerializable::toArray as serializeToArray;
    }
    use AsSorted;
    use AsStateful;
    use AsTestable;
    use AsThrottle;
    use AsTimeout;
    use AsTracer;
    use AsTransaction;
    use AsTransformer;
    use AsUpdatable;
    use AsValidated;
    use AsVersioned;
    use AsWatermarked;
    use AsWebhook;
}
