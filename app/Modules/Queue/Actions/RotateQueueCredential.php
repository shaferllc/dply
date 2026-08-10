<?php

declare(strict_types=1);

namespace App\Modules\Queue\Actions;

use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use RuntimeException;

/**
 * Mint a replacement credential without revoking the old one.
 *
 * Rotation cannot be atomic here. A queue credential lives in a `.env` that
 * only reaches the running app on its *next deploy*, so revoking the old
 * secret at the moment the new one is minted guarantees an outage for however
 * long the redeploy takes.
 *
 * So rotation is deliberately two-step: mint, redeploy, then revoke once the
 * old credential goes quiet. `last_used_at` on the old row is what tells the
 * operator it is safe — the UI surfaces "still in use, last seen N minutes
 * ago" rather than asking them to guess.
 *
 * Two live credentials is the cap. A third means a previous rotation was
 * never finished, and silently allowing more would let an abandoned secret
 * live forever.
 */
final class RotateQueueCredential
{
    public const MAX_LIVE_CREDENTIALS = 2;

    /**
     * @return array{credential: QueueCredential, plaintext: string}
     */
    public function handle(QueueNamespace $namespace, ?string $name = null, ?string $userId = null): array
    {
        $live = $namespace->liveCredentials();

        if ($live->count() >= self::MAX_LIVE_CREDENTIALS) {
            throw new RuntimeException(
                'This namespace already has '.self::MAX_LIVE_CREDENTIALS.' live credentials. '
                .'Revoke the one that is no longer in use before minting another.'
            );
        }

        $minted = QueueCredential::mint(
            $namespace,
            $name ?? __('Rotated :date', ['date' => now()->toDateString()]),
            userId: $userId,
        );

        return ['credential' => $minted['credential'], 'plaintext' => $minted['plaintext']];
    }
}
