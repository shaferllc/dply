<?php

namespace App\Actions;

use App\Actions\Concerns\AsJob;
use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Foundation\Bus\PendingDispatch;

class ActionPendingChain extends PendingChain
{
    public function dispatch(): ?PendingDispatch
    {
        /** @var $job AsJob */
        if ($this->usesAsJobTrait($job = $this->job)) {
            $this->job = $job::makeJob(...func_get_args());
        }

        return parent::dispatch();
    }

    public function usesAsJobTrait($job): bool
    {
        return is_string($job)
            && class_exists($job)
            && in_array(AsJob::class, class_uses_recursive($job));
    }
}
