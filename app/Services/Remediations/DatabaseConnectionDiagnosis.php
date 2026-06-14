<?php

declare(strict_types=1);

namespace App\Services\Remediations;

/**
 * The result of a Level-A (no-SSH) diagnosis of a database-connection deploy
 * failure: what kind of connection error it was, what state the site's database
 * wiring is in, and which guided fixes to offer (ordered, first = primary).
 *
 * Produced by {@see DatabaseConnectionDiagnostic} purely from data dply already
 * holds — the failure text, the site's attached databases/bindings, and the
 * DB_* it resolved into the env — so it is safe to compute on render. The
 * deeper "is the engine actually installed/running" question is deferred to the
 * attach modal (a user action), per the no-render-path-SSH rule.
 */
final class DatabaseConnectionDiagnosis
{
    public const SUBCLASS_REFUSED = 'refused';

    public const SUBCLASS_HOST_UNKNOWN = 'host_unknown';

    public const SUBCLASS_AUTH_FAILED = 'auth_failed';

    public const SUBCLASS_UNKNOWN_DB = 'unknown_db';

    public const STATE_NO_DB = 'no_db';

    public const STATE_ATTACHED = 'attached';

    public const STATE_REMOTE = 'remote';

    public const STATE_SQLITE = 'sqlite';

    // Guided action keys the panel knows how to render.
    public const ACTION_ATTACH = 'attach';

    public const ACTION_INJECT = 'inject';

    public const ACTION_OPEN_DATABASE = 'open_database';

    /**
     * @param  list<string>  $actions  ordered guided action keys; first is the recommended/primary fix
     */
    public function __construct(
        public readonly string $subclass,
        public readonly string $state,
        public readonly ?string $engineFamily,
        public readonly ?string $envHost,
        public readonly ?string $envConnection,
        public readonly string $headline,
        public readonly string $detail,
        public readonly array $actions,
    ) {}

    public function recommends(string $action): bool
    {
        return in_array($action, $this->actions, true);
    }

    public function primaryAction(): ?string
    {
        return $this->actions[0] ?? null;
    }
}
