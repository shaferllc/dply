<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * GitIdentity models expose id(), provider(), and accessToken() methods that
 * collide with Eloquent attribute / relationship resolution — reading $model->id
 * or booting HasUlids can recurse until max_execution_time. Route column reads
 * through attributes[] instead.
 */
trait AvoidsGitIdentityAttributeRecursion
{
    /**
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if ($key === $this->getKeyName()) {
            return $this->attributes[$key] ?? null;
        }

        if ($key === 'provider') {
            return $this->attributes['provider'] ?? null;
        }

        if ($key === 'access_token') {
            return array_key_exists('access_token', $this->attributes)
                ? $this->castAttribute('access_token', $this->attributes['access_token'])
                : null;
        }

        return parent::getAttribute($key);
    }

    public function id(): string
    {
        return (string) ($this->attributes[$this->getKeyName()] ?? '');
    }

    public function provider(): string
    {
        return (string) ($this->attributes['provider'] ?? '');
    }
}
