<?php

namespace App\Modules\ConfigRevisions\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Bundle of "who/what owns this revision" passed to the recorder so we
 * don't end up with a 5-argument capture() signature. The fields are
 * denormalized onto the row for indexing/filtering, but stream identity
 * is the caller's stream_key — not this context.
 */
final class ConfigRevisionContext
{
    public function __construct(
        public readonly ?Server $server = null,
        public readonly ?Model $subject = null,
        public readonly ?User $user = null,
        public readonly ?string $summary = null,
    ) {}
}
