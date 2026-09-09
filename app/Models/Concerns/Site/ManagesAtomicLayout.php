<?php

declare(strict_types=1);

namespace App\Models\Concerns\Site;

use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Database\Eloquent\Collection;

/**
 * On-disk atomic-layout conversion state. The strategy column is truth for
 * "disk is atomic"; these helpers cover the in-flight convert / disable window
 * when the column has not flipped yet.
 *
 * @phpstan-require-extends Site
 */
trait ManagesAtomicLayout
{
    public function isConvertingAtomicLayout(): bool
    {
        return $this->atomicLayoutStatus() === 'converting';
    }

    public function isDisablingAtomicLayout(): bool
    {
        if ($this->atomicLayoutStatus() === 'disabling') {
            return true;
        }

        $armed = $this->deployLayoutMigration();

        return is_array($armed) && ($armed['to'] ?? '') === 'flat';
    }

    public function atomicLayoutStatus(): ?string
    {
        $status = data_get($this->meta, 'atomic_layout.status');

        return is_string($status) && $status !== '' ? $status : null;
    }

    /**
     * @return array{from?: string, to?: string, armed_at?: string}|null
     */
    public function deployLayoutMigration(): ?array
    {
        $armed = data_get($this->meta, 'deploy_layout_migration');

        return is_array($armed) ? $armed : null;
    }

    public function hasRunningDeployment(): bool
    {
        return $this->deployments()
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->exists();
    }

    /**
     * Worker-pool replicas of this site (site-attached pool), oldest first.
     *
     * @return Collection<int, Site>
     */
    public function workerReplicaSites(): Collection
    {
        return static::query()
            ->where('meta->replicated_from_site_id', (string) $this->id)
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Hosts to convert: replicas first (lower blast radius), then this site.
     *
     * @return list<Site>
     */
    public function atomicLayoutConvertHosts(): array
    {
        $hosts = [];
        foreach ($this->workerReplicaSites() as $replica) {
            $hosts[] = $replica;
        }
        $hosts[] = $this;

        return $hosts;
    }
}
