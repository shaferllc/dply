<?php

declare(strict_types=1);

namespace App\Services\Remediations;

use App\Jobs\ApplyRemediationJob;

/**
 * Matches failure text against the {@see config('remediations')} catalog and
 * resolves remediations / actions by code. Shared by every surface that wants
 * to offer a fix for a recognized error (the deploy console, the Errors view).
 */
class RemediationCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('remediations', []);
    }

    /**
     * The first remediation whose signature matches the given error text, with
     * its `code` merged in. Null when nothing matches.
     *
     * @return array<string, mixed>|null
     */
    public function match(?string $errorText): ?array
    {
        $text = trim((string) $errorText);
        if ($text === '') {
            return null;
        }

        foreach ($this->all() as $code => $remediation) {
            $signature = $remediation['signature'] ?? null;
            if (is_string($signature) && $signature !== '' && @preg_match($signature, $text) === 1) {
                return ['code' => $code] + $remediation;
            }
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public function find(string $code): ?array
    {
        $remediation = $this->all()[$code] ?? null;

        return is_array($remediation) ? ['code' => $code] + $remediation : null;
    }

    /**
     * The allow-list of class-backed handlers: every `handler` declared by any
     * action in the catalog. {@see ApplyRemediationJob} checks a
     * resolved handler against this set before instantiating it, so only a class
     * explicitly wired into the static config can ever be constructed — the trust
     * boundary is the catalog, not "any class implementing the interface."
     *
     * @return list<class-string>
     */
    public function handlerClasses(): array
    {
        $classes = [];
        foreach ($this->all() as $remediation) {
            foreach (($remediation['actions'] ?? []) as $action) {
                $handler = $action['handler'] ?? null;
                if (is_string($handler) && $handler !== '') {
                    $classes[$handler] = true;
                }
            }
        }

        return array_keys($classes);
    }

    /**
     * Resolve a single action within a remediation.
     *
     * @return array<string, mixed>|null
     */
    public function action(string $code, string $actionKey): ?array
    {
        $remediation = $this->find($code);
        foreach (($remediation['actions'] ?? []) as $action) {
            if (($action['key'] ?? null) === $actionKey) {
                return $action;
            }
        }

        return null;
    }
}
