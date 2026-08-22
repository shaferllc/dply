<?php

declare(strict_types=1);

namespace App\Modules\Queue\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Queue\Contracts\QueueStore;
use App\Models\ServiceCredential;
use App\Modules\Queue\Support\ClaimedJob;
use App\Modules\Queue\Support\QueueAction;
use App\Modules\Queue\Support\QueueEntitlements;
use App\Modules\Queue\Support\QueueRequestContext;
use App\Modules\Queue\Services\FleetWaker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The SQS-compatible surface of dply Queue.
 *
 * Speaks AWS JSON 1.0 — POST, `X-Amz-Target: AmazonSQS.<Action>`, JSON body,
 * SigV4 — which is what the AWS SDK's SQS client actually sends, and therefore
 * what Laravel's built-in `sqs` queue driver sends. A customer sets three env
 * vars and their existing app works; there is no package to install, and
 * nothing for us to version against future Laravel releases.
 *
 * Same play as Realtime, which shipped no SDK by speaking the Pusher protocol.
 *
 * Two SQS concepts map onto the store directly, which is why this is a thin
 * adapter rather than a translation layer:
 *   - ReceiptHandle IS the fencing token (`reservation_id`).
 *   - VisibilityTimeout IS the lease on `visible_at`.
 *
 * Only the actions Laravel's driver uses are implemented. Anything else gets
 * a clean `InvalidAction` rather than a 404, so a client using a broader SQS
 * feature learns what happened.
 */
class SqsCompatibilityController extends Controller
{
    /** Hard ceiling on one ReceiveMessage, matching SQS. */
    private const MAX_RECEIVE = 10;

    public function __construct(
        private readonly QueueStore $store,
        private readonly QueueEntitlements $entitlements,
        private readonly FleetWaker $waker,
    ) {}

    /**
     * Decoded request body.
     *
     * Read explicitly rather than through `$request->input()`: the AWS JSON
     * protocol sends `Content-Type: application/x-amz-json-1.0`, which Laravel
     * does not recognise as JSON, so the input bag would be empty and every
     * field would silently read as its default.
     *
     * @var array<string, mixed>
     */
    private array $body = [];

    public function __invoke(Request $request): JsonResponse
    {
        $context = $request->attributes->get('queue_context');

        if (! $context instanceof QueueRequestContext) {
            return $this->error('AccessDenied', 'Unauthenticated.', 403);
        }

        $decoded = json_decode($request->getContent(), true);
        $this->body = is_array($decoded) ? $decoded : [];

        $action = QueueAction::of($request);

        return match ($action) {
            'SendMessage' => $this->sendMessage($request, $context),
            'SendMessageBatch' => $this->sendMessageBatch($request, $context),
            'ReceiveMessage' => $this->receiveMessage($request, $context),
            'DeleteMessage' => $this->deleteMessage($request, $context),
            'DeleteMessageBatch' => $this->deleteMessageBatch($request, $context),
            'ChangeMessageVisibility' => $this->changeMessageVisibility($request, $context),
            'GetQueueAttributes' => $this->getQueueAttributes($request, $context),
            'GetQueueUrl' => $this->getQueueUrl($request, $context),
            default => $this->error('InvalidAction', 'dply Queue does not implement '.($action ?: 'this action').'.', 400),
        };
    }

    private function sendMessage(Request $request, QueueRequestContext $context): JsonResponse
    {
        if (! $context->allows(ServiceCredential::SCOPE_PUSH)) {
            return $this->error('AccessDenied', 'This credential cannot push.', 403);
        }

        $body = (string) $this->field('MessageBody', '');

        if ($body === '') {
            return $this->error('InvalidParameterValue', 'MessageBody is required.', 400);
        }

        $guard = $this->guardPush($context, [$body]);
        if ($guard !== null) {
            return $guard;
        }

        $queue = $this->queueName($request);

        $id = $this->store->push(
            $context->namespace,
            $queue,
            $body,
            (int) $this->field('DelaySeconds', 0),
        );

        $this->wakeDrainers($context, $queue);

        return $this->ok([
            'MessageId' => $id,
            'MD5OfMessageBody' => md5($body),
        ]);
    }

    private function sendMessageBatch(Request $request, QueueRequestContext $context): JsonResponse
    {
        if (! $context->allows(ServiceCredential::SCOPE_PUSH)) {
            return $this->error('AccessDenied', 'This credential cannot push.', 403);
        }

        $entries = $this->field('Entries', []);

        if (! is_array($entries) || $entries === []) {
            return $this->error('EmptyBatchRequest', 'Entries is required.', 400);
        }

        $bodies = [];
        $ids = [];
        $delay = 0;

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $bodies[] = (string) ($entry['MessageBody'] ?? '');
            $ids[] = (string) ($entry['Id'] ?? '');
            $delay = max($delay, (int) ($entry['DelaySeconds'] ?? 0));
        }

        $guard = $this->guardPush($context, $bodies);
        if ($guard !== null) {
            return $guard;
        }

        $queue = $this->queueName($request);

        $messageIds = $this->store->pushBulk(
            $context->namespace,
            $queue,
            $bodies,
            $delay,
        );

        $this->wakeDrainers($context, $queue);

        $successful = [];
        foreach ($messageIds as $index => $messageId) {
            $successful[] = [
                'Id' => $ids[$index] ?? (string) $index,
                'MessageId' => $messageId,
                'MD5OfMessageBody' => md5($bodies[$index] ?? ''),
            ];
        }

        return $this->ok(['Successful' => $successful, 'Failed' => []]);
    }

    private function receiveMessage(Request $request, QueueRequestContext $context): JsonResponse
    {
        if (! $context->allows(ServiceCredential::SCOPE_POP)) {
            return $this->error('AccessDenied', 'This credential cannot receive.', 403);
        }

        $max = max(1, min(self::MAX_RECEIVE, (int) $this->field('MaxNumberOfMessages', 1)));
        $visibility = (int) $this->field('VisibilityTimeout', 0);

        $claimed = $this->longPoll(
            $context,
            $this->queueName($request),
            $max,
            $visibility > 0 ? $visibility : null,
            (int) $this->field('WaitTimeSeconds', 0),
        );

        $messages = array_map(fn ($job): array => [
            'MessageId' => $job->id,
            // The reservation id IS the receipt handle. SQS's own model is a
            // fencing token; we just say so out loud.
            'ReceiptHandle' => $job->id.':'.$job->reservationId,
            'Body' => $job->payload,
            'MD5OfBody' => md5($job->payload),
            'Attributes' => [
                'ApproximateReceiveCount' => (string) $job->attempts,
            ],
        ], $claimed);

        // SQS omits the key entirely when empty, and the SDK tolerates both.
        return $this->ok($messages === [] ? [] : ['Messages' => $messages]);
    }

    private function deleteMessage(Request $request, QueueRequestContext $context): JsonResponse
    {
        if (! $context->allows(ServiceCredential::SCOPE_POP)) {
            return $this->error('AccessDenied', 'This credential cannot delete.', 403);
        }

        $handle = $this->receipt($request);

        if ($handle === null) {
            return $this->error('ReceiptHandleIsInvalid', 'ReceiptHandle is malformed.', 400);
        }

        [$jobId, $reservationId] = $handle;

        if (! $this->store->ack($context->namespace, $jobId, $reservationId)) {
            // The job exists but is held under a newer reservation: this
            // caller's lease expired and someone else owns the work now.
            // Deleting would destroy their job silently.
            return $this->error(
                'ReceiptHandleIsInvalid',
                'That reservation has expired and the message is held by another consumer.',
                400,
            );
        }

        return $this->ok([]);
    }

    /**
     * Delete up to ten completed messages in one request.
     *
     * This is the throughput lever. Acking one job per request means a drain
     * costs two round trips per job and burns two of the namespace's
     * per-minute allowance; batching the deletes collapses the second half of
     * that to a tenth. Matches SQS's own entry/result shape so a client that
     * already knows `DeleteMessageBatch` needs no special case.
     */
    private function deleteMessageBatch(Request $request, QueueRequestContext $context): JsonResponse
    {
        if (! $context->allows(ServiceCredential::SCOPE_POP)) {
            return $this->error('AccessDenied', 'This credential cannot delete.', 403);
        }

        $entries = $this->field('Entries', []);

        if (! is_array($entries) || $entries === []) {
            return $this->error('EmptyBatchRequest', 'Entries is required.', 400);
        }

        if (count($entries) > self::MAX_RECEIVE) {
            return $this->error('TooManyEntriesInBatchRequest', 'A batch holds at most '.self::MAX_RECEIVE.' entries.', 400);
        }

        $pairs = [];
        $entryIdByJob = [];
        $failed = [];

        foreach ($entries as $index => $entry) {
            $entryId = is_array($entry) ? (string) ($entry['Id'] ?? $index) : (string) $index;
            $handle = is_array($entry) ? (string) ($entry['ReceiptHandle'] ?? '') : '';

            $parsed = $this->parseReceipt($handle);

            if ($parsed === null) {
                $failed[] = [
                    'Id' => $entryId,
                    'Code' => 'ReceiptHandleIsInvalid',
                    'Message' => 'ReceiptHandle is malformed.',
                    'SenderFault' => true,
                ];

                continue;
            }

            $pairs[] = $parsed;
            // Last entry wins on a duplicated job id, which is also what the
            // delete itself does — the result must not claim two outcomes.
            $entryIdByJob[$parsed[0]] = $entryId;
        }

        $results = $pairs === [] ? [] : $this->store->ackBulk($context->namespace, $pairs);

        $successful = [];
        foreach ($results as $jobId => $ok) {
            $entryId = $entryIdByJob[$jobId] ?? $jobId;

            if ($ok) {
                $successful[] = ['Id' => $entryId];

                continue;
            }

            $failed[] = [
                'Id' => $entryId,
                'Code' => 'ReceiptHandleIsInvalid',
                'Message' => 'That reservation has expired and the message is held by another consumer.',
                'SenderFault' => true,
            ];
        }

        return $this->ok(['Successful' => $successful, 'Failed' => $failed]);
    }

    private function changeMessageVisibility(Request $request, QueueRequestContext $context): JsonResponse
    {
        if (! $context->allows(ServiceCredential::SCOPE_POP)) {
            return $this->error('AccessDenied', 'This credential cannot change visibility.', 403);
        }

        $handle = $this->receipt($request);

        if ($handle === null) {
            return $this->error('ReceiptHandleIsInvalid', 'ReceiptHandle is malformed.', 400);
        }

        [$jobId, $reservationId] = $handle;
        $timeout = max(0, (int) $this->field('VisibilityTimeout', 0));

        // Zero means "make it visible now" — Laravel's driver uses this to
        // release a job back to the queue.
        $ok = $timeout === 0
            ? $this->store->release($context->namespace, $jobId, $reservationId, 0)
            : $this->store->heartbeat($context->namespace, $jobId, $reservationId, $timeout);

        if (! $ok) {
            return $this->error('ReceiptHandleIsInvalid', 'That reservation is no longer valid.', 400);
        }

        return $this->ok([]);
    }

    private function getQueueAttributes(Request $request, QueueRequestContext $context): JsonResponse
    {
        $depth = $this->store->depth($context->namespace, $this->queueName($request));

        return $this->ok([
            'Attributes' => [
                'ApproximateNumberOfMessages' => (string) $depth->pending,
                'ApproximateNumberOfMessagesNotVisible' => (string) $depth->reserved,
                'ApproximateNumberOfMessagesDelayed' => (string) $depth->delayed,
            ],
        ]);
    }

    private function getQueueUrl(Request $request, QueueRequestContext $context): JsonResponse
    {
        $name = (string) $this->field('QueueName', 'default');

        return $this->ok([
            'QueueUrl' => rtrim((string) config('queue_service.public_url', url('/api/queue/v1')), '/').'/'.$name,
        ]);
    }

    /**
     * Bounded long poll.
     *
     * Capped hard, because a held request pins a PHP-FPM worker: at many
     * concurrent drains, FPM is exhausted long before Postgres notices. Even a
     * couple of seconds is a large improvement on a worker's default
     * `--sleep=3`. Raising this meaningfully needs the dedicated async pop
     * pool, not a bigger number here.
     *
     * @return list<ClaimedJob>
     */
    private function longPoll(
        QueueRequestContext $context,
        string $queue,
        int $max,
        ?int $visibility,
        int $requestedWait,
    ): array {
        $maxWait = (int) config('queue_service.long_poll.max_seconds', 5);
        $wait = max(0, min($maxWait, $requestedWait));
        $intervalMs = max(50, (int) config('queue_service.long_poll.interval_ms', 250));
        $ceilingMs = max($intervalMs, (int) config('queue_service.long_poll.max_interval_ms', 1000));

        $deadline = microtime(true) + $wait;
        $sleepMs = $intervalMs;

        do {
            $claimed = $this->store->claim($context->namespace, $queue, $max, $visibility);

            if ($claimed !== []) {
                return $claimed;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                return [];
            }

            // Back off as the wait wears on. A queue that was empty 250ms ago
            // is usually still empty, and a flat interval spends the same query
            // budget on second five as on the first — the tail of a long poll
            // is where a fixed loop wastes the most for the least.
            //
            // Never sleep past the deadline: overshooting would hold the
            // request (and its FPM worker) beyond what the client asked for.
            usleep((int) (min($sleepMs / 1000, $remaining) * 1_000_000));

            $sleepMs = min($ceilingMs, $sleepMs * 2);
        } while (true);
    }

    /**
     * Depth and payload-size limits. These protect the shared table from one
     * tenant, and are enforced as a rejection rather than billed for.
     *
     * @param  list<string>  $bodies
     */
    private function guardPush(QueueRequestContext $context, array $bodies): ?JsonResponse
    {
        // A paused namespace rejects pushes but keeps serving receives, so an
        // operator (or a plan downgrade) can stop the inflow without stranding
        // the jobs already in the queue — draining is how it gets emptied.
        if (! $context->namespace->acceptsPushes()) {
            return $this->error(
                'RequestThrottled',
                'This queue is paused and is not accepting new messages. Resume it from the dply dashboard.',
                403,
            );
        }

        $organization = $context->namespace->organization;

        if ($organization === null) {
            return null;
        }

        $entitlement = $this->entitlements->for($organization);

        foreach ($bodies as $body) {
            if ($entitlement->maxPayloadBytes > 0 && strlen($body) > $entitlement->maxPayloadBytes) {
                return $this->error(
                    'InvalidParameterValue',
                    'Message body exceeds the '.$entitlement->maxPayloadBytes.' byte limit for this plan.',
                    400,
                );
            }
        }

        // Depth comes from the namespace row, not the org's plan: capacity is
        // bought per namespace by tier, and the row holds what was bought.
        // Reading it live from config would let a tier re-price shrink a
        // running queue underneath the customer.
        $maxDepth = (int) ($context->namespace->max_queue_depth ?? 0);

        if ($maxDepth > 0) {
            $depth = $this->store->depth($context->namespace)->total();

            if ($depth + count($bodies) > $maxDepth) {
                return $this->error(
                    'OverLimit',
                    'This queue is at its depth limit of '.$maxDepth.' messages. Drain it or move it to a larger tier.',
                    403,
                );
            }
        }

        return null;
    }

    /**
     * Start draining immediately when the namespace belongs to a function dply
     * deployed.
     *
     * This is the payoff of hosting the store: dply knows a job arrived the
     * moment it lands, so there is nothing to poll and nothing for the app to
     * tell us.
     */
    private function wakeDrainers(QueueRequestContext $context, string $queue): void
    {
        $this->waker->wake($context->namespace, $queue);
    }

    /**
     * `<jobId>:<reservationId>` — the fencing token travels with the handle,
     * so every mutation can be checked against the reservation it was issued.
     *
     * @return array{0: string, 1: string}|null
     */
    private function receipt(Request $request): ?array
    {
        return $this->parseReceipt((string) $this->field('ReceiptHandle', ''));
    }

    /**
     * Split a receipt handle into its job id and fencing token.
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseReceipt(string $handle): ?array
    {
        if (! str_contains($handle, ':')) {
            return null;
        }

        [$jobId, $reservationId] = explode(':', $handle, 2);
        $jobId = trim($jobId);
        $reservationId = trim($reservationId);

        if ($jobId === '' || $reservationId === '') {
            return null;
        }

        // The reservation id is a uuid and is cast as one in the batch delete;
        // rejecting a malformed handle here keeps a bad string from reaching
        // Postgres as a cast error that would fail the whole batch.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reservationId) !== 1) {
            return null;
        }

        return [$jobId, $reservationId];
    }

    /**
     * SQS addresses a queue by URL; Laravel's driver puts the queue name in
     * the last path segment. The namespace always comes from the credential,
     * never from here — the URL only selects which queue inside it.
     */
    private function queueName(Request $request): string
    {
        // The SDK puts the queue URL in the body for most actions.
        $url = (string) $this->field('QueueUrl', '');

        if ($url !== '') {
            $segment = basename(parse_url($url, PHP_URL_PATH) ?: '');
            if ($segment !== '' && $segment !== 'v1') {
                return $segment;
            }
        }

        $name = (string) $this->field('QueueName', '');

        if ($name !== '') {
            return $name;
        }

        // Falls back to the path, so a client can address a queue purely by
        // URL — which is what a plain HTTP caller (or curl) would do.
        $route = $request->route('queue');

        return is_string($route) && $route !== '' ? $route : 'default';
    }

    /** One field out of the decoded AWS JSON body. */
    private function field(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ok(array $payload): JsonResponse
    {
        return response()->json($payload, 200, ['Content-Type' => 'application/x-amz-json-1.0']);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            '__type' => 'com.amazonaws.sqs#'.$code,
            'message' => $message,
        ], $status, ['Content-Type' => 'application/x-amz-json-1.0']);
    }
}
