<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One reason a site cannot be created right now, in a form every surface can
 * use: the web wizards render `message` inline, and the API returns the whole
 * thing so `dply init` can name the blocker, point at the page that clears it,
 * and retry without re-asking anything.
 *
 * Kernel rather than per-module because more than one product creates sites
 * and none of them should have to depend on another to describe why it could
 * not. Codes are the contract — CLI behaviour switches on them — so they are
 * added, never renamed.
 *
 * @see docs/adr/cli-init-and-site-creation.md
 */
final class SiteCreateBlocker
{
    /* Shared across products. */
    public const SURFACE_DISABLED = 'surface_disabled';

    public const CLI_CREATE_DISABLED = 'cli_create_disabled';

    public const FORBIDDEN = 'forbidden';

    public const QUOTA_EXCEEDED = 'quota_exceeded';

    public const TRIAL_PAUSED = 'trial_paused';

    public const INVALID_REGION = 'invalid_region';

    public const SOURCE_REQUIRED = 'source_required';

    /* Serverless. */
    public const MANAGED_UNAVAILABLE = 'managed_unavailable';

    public const NO_PROVIDER_CREDENTIAL = 'no_provider_credential';

    public const CREDENTIAL_UNHEALTHY = 'credential_unhealthy';

    /* Cloud. */
    public const NO_BACKEND = 'no_backend';

    public const SPEC_REJECTED = 'spec_rejected';

    public const SOURCE_UNSUPPORTED = 'source_unsupported';

    public function __construct(
        public readonly string $code,
        public readonly string $message,
        /** Page that clears this blocker, relative to the instance root. */
        public readonly ?string $resolvePath = null,
        /** A CLI command that clears it, when one exists. */
        public readonly ?string $resolveCommand = null,
    ) {}

    /**
     * @return array{code: string, message: string, resolve_url: ?string, resolve_command: ?string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'resolve_url' => $this->resolvePath !== null ? url($this->resolvePath) : null,
            'resolve_command' => $this->resolveCommand,
        ];
    }
}
