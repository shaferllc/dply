<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteAccessGate;
use App\Models\SiteBasicAuthUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * Extracted from {@see Site}. Composed back into the model via `use`.
 */
trait GuardsSiteAccess
{
    /**
     * On-host directory for Dply-managed htpasswd files (under the site repo root, not web-served).
     */
    public function basicAuthStorageDirectoryOnHost(): string
    {
        return rtrim($this->effectiveRepositoryPath(), '/').'/.dply/basic-auth';
    }

    /**
     * Absolute path for {@see auth_basic_user_file} / Apache {@see AuthUserFile} for a normalized path group.
     */
    public function basicAuthHtpasswdPathForNormalizedPath(string $normalizedPath): string
    {
        $key = SiteBasicAuthUser::normalizePath($normalizedPath);
        $hash = substr(hash('sha256', $key), 0, 16);

        return $this->basicAuthStorageDirectoryOnHost().'/group-'.$hash.'.htpasswd';
    }

    public function supportsBasicAuthProvisioning(): bool
    {
        if ($this->usesFunctionsRuntime() || $this->usesDockerRuntime() || $this->usesKubernetesRuntime()) {
            return false;
        }

        $server = $this->server;
        if ($server === null || ! $server->hostCapabilities()->supportsSsh()) {
            return false;
        }

        return in_array($this->webserver(), [
            'nginx',
            'apache',
            'caddy',
            'traefik',
            'openlitespeed',
        ], true);
    }

    public function supportsAccessGateProvisioning(): bool
    {
        return $this->supportsBasicAuthProvisioning();
    }

    /**
     * Form password gate is not wired for OpenLiteSpeed in v1.
     */
    public function webserverSupportsFormPasswordGate(): bool
    {
        if (! $this->supportsAccessGateProvisioning()) {
            return false;
        }

        return $this->webserver() !== 'openlitespeed';
    }

    public function resolvedAccessGateMethod(): string
    {
        $this->loadMissing(['accessGate', 'accessGatePasswords', 'basicAuthUsers']);

        $gate = $this->accessGate;
        if ($gate !== null && $gate->isFormPasswordActive()) {
            return SiteAccessGate::METHOD_FORM_PASSWORD;
        }

        if ($gate !== null && $gate->method === SiteAccessGate::METHOD_OFF) {
            return SiteAccessGate::METHOD_OFF;
        }

        if ($this->enforceableBasicAuthUsers()->isNotEmpty()) {
            return SiteAccessGate::METHOD_BASIC_AUTH;
        }

        if ($gate !== null && $gate->method === SiteAccessGate::METHOD_BASIC_AUTH) {
            return SiteAccessGate::METHOD_BASIC_AUTH;
        }

        return SiteAccessGate::METHOD_OFF;
    }

    public function usesFormPasswordGate(): bool
    {
        return $this->resolvedAccessGateMethod() === SiteAccessGate::METHOD_FORM_PASSWORD
            && $this->webserverSupportsFormPasswordGate();
    }

    public function usesBasicAuthGate(): bool
    {
        if ($this->usesFormPasswordGate()) {
            return false;
        }

        return $this->enforceableBasicAuthUsers()->isNotEmpty()
            || $this->resolvedAccessGateMethod() === SiteAccessGate::METHOD_BASIC_AUTH;
    }

    public function accessGateStorageDirectoryOnHost(): string
    {
        return rtrim($this->effectiveRepositoryPath(), '/').'/.dply/access-gate';
    }

    public function accessGateScriptPathOnHost(): string
    {
        return $this->accessGateStorageDirectoryOnHost().'/index.php';
    }

    public function accessGateConfigPathOnHost(): string
    {
        return $this->accessGateStorageDirectoryOnHost().'/config.json';
    }

    public function accessGateLoginLogPathOnHost(): string
    {
        return $this->accessGateStorageDirectoryOnHost().'/logins.jsonl';
    }

    /**
     * Unix account executing the on-host gate script via PHP-FPM. Stock pools use
     * www-data; explicit {@see $php_fpm_user} overrides when a site has its own pool.
     */
    public function accessGatePhpRuntimeUser(Server $server): string
    {
        $explicit = trim((string) ($this->php_fpm_user ?? ''));

        if ($explicit !== '') {
            return $explicit;
        }

        return 'www-data';
    }

    /**
     * Hash stored for basic-auth credentials. Caddy (primary or edge backend) needs
     * bcrypt inline; file-based engines (nginx, Apache, Traefik, OLS) use apr1 in
     * htpasswd files.
     */
    public function hashBasicAuthPassword(string $plaintext): string
    {
        $this->loadMissing('server');

        if ($this->webserver() === 'caddy' || $this->server?->hasEdgeProxy()) {
            return Hash::make($plaintext);
        }

        return SiteBasicAuthUser::apr1Hash($plaintext);
    }

    /**
     * Path-prefix basic auth (e.g. /wp-admin) is emitted for static and non-Octane PHP nginx configs.
     */
    public function basicAuthSupportsPathPrefixes(): bool
    {
        if ($this->type === SiteType::Static) {
            return true;
        }

        return $this->type === SiteType::Php && ! $this->octane_port;
    }
}
