<?php

namespace App\Actions;

use App\Actions\Concerns\AsJob;
use Illuminate\Foundation\Bus\PendingChain;
use Illuminate\Foundation\Bus\PendingDispatch;

class ActionPendingChain extends PendingChain
{
    public function dispatch(): ?PendingDispatch
    {
        // $job is a class-string here (usesAsJobTrait() checks is_string +
        // class_exists), not an instance — the old `@var AsJob $job` named a
        // trait, which is not a valid type at all. method_exists() is what lets
        // PHPStan see that the AsJob trait supplies makeJob().
        $job = $this->job;
        if ($this->usesAsJobTrait($job) && is_string($job) && method_exists($job, 'makeJob')) {
            $this->job = $job::makeJob(...func_get_args());
        }

        return parent::dispatch();
    }

    /**
     * @param  mixed  $job
     */
    public function usesAsJobTrait($job): bool
    {
        return is_string($job)
            && class_exists($job)
            && in_array(AsJob::class, class_uses_recursive($job));
    }
}
