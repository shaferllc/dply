<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;
use App\Modules\Serverless\Contracts\ServerlessFeature;
use App\Modules\Serverless\Contracts\SupportsAsyncInvocation;
use App\Modules\Serverless\Jobs\PollFunctionActivationJob;
use App\Modules\Serverless\Models\FunctionInvocation;
use Illuminate\Support\Carbon;

/**
 * Starts an invocation without waiting for it, and hands the outcome to a
 * poller.
 *
 * {@see FunctionInvoker} is the blocking path: it holds the HTTP call open
 * and gets the whole activation back inline. That caps out at the platform's
 * synchronous limit — 60s on DigitalOcean Functions — and well before that at
 * dply's own request budget. A function allowed the full 15-minute timeout
 * can only be driven from here.
 *
 * The row is written immediately in `pending` state so the invocation is
 * visible on the Logs page while it runs, then completed in place by
 * {@see PollFunctionActivationJob}.
 */
class AsyncFunctionInvoker
{
    public function __construct(
        private readonly ServerlessProvisionerLocator $provisioners,
        private readonly ServerlessFeatureMatrix $features,
    ) {}

    /**
     * @param  array<string, mixed>  $owArgs  The raw web-action event the handler will see.
     * @return array{ok: bool, error: ?string, invocation: ?FunctionInvocation}
     */
    public function invoke(Site $site, string $source, ?string $task, array $owArgs): array
    {
        $site->loadMissing('server');

        if (! $this->features->siteSupports($site, ServerlessFeature::AsyncInvocation)) {
            return [
                'ok' => false,
                'error' => __('This host cannot run invocations asynchronously.'),
                'invocation' => null,
            ];
        }

        $actionName = $site->serverlessActionName();
        if ($actionName === '') {
            return ['ok' => false, 'error' => __('This function has not been deployed yet.'), 'invocation' => null];
        }

        $provisioner = $this->provisioners->forSite($site);
        if (! $provisioner instanceof SupportsAsyncInvocation) {
            return ['ok' => false, 'error' => __('The function host is not provisioned yet.'), 'invocation' => null];
        }

        // Mark the event as dply-initiated so the handler skips its organic
        // ingest POST — the poller records this invocation from the
        // activation record instead.
        $headers = is_array($owArgs['__ow_headers'] ?? null) ? $owArgs['__ow_headers'] : [];
        $headers['x-dply-source'] = $source;
        $owArgs['__ow_headers'] = $headers;

        $result = $provisioner->invokeAsync($actionName, $owArgs, $this->provisioners->contextForSite($site));

        if (! $result['ok'] || $result['activation_id'] === null) {
            $error = (string) ($result['error'] ?? __('The host rejected the invocation.'));

            return [
                'ok' => false,
                'error' => $error,
                // An invisible failed invocation is worse than a visible one.
                'invocation' => $this->row($site, $source, $task, $owArgs, [
                    'state' => FunctionInvocation::STATE_FAILED,
                    'result_excerpt' => $error,
                ]),
            ];
        }

        $invocation = $this->row($site, $source, $task, $owArgs, [
            'state' => FunctionInvocation::STATE_PENDING,
            'activation_id' => $result['activation_id'],
        ]);

        PollFunctionActivationJob::dispatch($invocation->id)->delay(now()->addSeconds(2));

        return ['ok' => true, 'error' => null, 'invocation' => $invocation];
    }

    /**
     * @param  array<string, mixed>  $owArgs
     * @param  array<string, mixed>  $attributes
     */
    private function row(Site $site, string $source, ?string $task, array $owArgs, array $attributes): FunctionInvocation
    {
        return FunctionInvocation::query()->create(array_merge([
            'site_id' => $site->id,
            'source' => $source,
            'task' => $task,
            'method' => strtoupper((string) ($owArgs['__ow_method'] ?? 'GET')),
            'path' => '/'.ltrim((string) ($owArgs['__ow_path'] ?? ''), '/'),
            'success' => false,
            'duration_ms' => 0,
            'cold' => false,
            'log_lines' => [],
            'created_at' => Carbon::now(),
        ], $attributes));
    }
}
