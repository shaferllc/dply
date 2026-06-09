<?php

declare(strict_types=1);

namespace App\Contracts\SourceControl;

/**
 * Shared shape for anything that can authenticate against a Git host on behalf
 * of a user. Implemented by SocialAccount (OAuth) and GitProviderToken (PAT)
 * so the SourceControl service layer doesn't have to branch on storage.
 */
interface GitIdentity
{
    /**
     * Opaque stable identifier (the model's ULID). Used in wizard dropdowns
     * and persisted in Site.git_source_control_account_id.
     */
    public function id(): string;

    /**
     * 'github' | 'gitlab' | 'bitbucket'.
     */
    public function provider(): string;

    /**
     * Bearer/clone token. Empty string when the identity is unusable.
     */
    public function accessToken(): string;

    /**
     * Human-friendly name for menus and tables.
     */
    public function displayLabel(): string;

    /**
     * Base URL for REST API calls — e.g. https://api.github.com,
     * https://gitlab.com, https://api.bitbucket.org. Returns the cloud
     * default when no override is configured.
     */
    public function apiBaseUrl(): string;

    /**
     * 'oauth' | 'pat'. Used by the UI to badge rows and by the webhook
     * UX to surface scope advice.
     */
    public function kind(): string;
}
