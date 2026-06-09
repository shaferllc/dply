<?php

declare(strict_types=1);

namespace App\Services\DeployContract;

/**
 * Repo-declared promote requirements (dply-contract.yaml or dply.yaml `contract:`).
 *
 * @phpstan-type PolicyArray array{
 *   requires?: list<string>,
 *   min_replay_pass_rate?: float|int,
 *   require_replay?: bool,
 * }
 */
final class DeployContractPolicy
{
    /** @param list<string> $requires */
    public function __construct(
        public readonly array $requires = [],
        public readonly ?float $minReplayPassRate = null,
        public readonly ?bool $requireReplay = null,
    ) {}

    public static function defaults(): self
    {
        return new self;
    }

    /**
     * @param  PolicyArray|null  $contract
     */
    public static function fromRepoConfig(?array $contract): self
    {
        if ($contract === null || $contract === []) {
            return self::defaults();
        }

        $requires = [];
        if (isset($contract['requires']) && is_array($contract['requires'])) {
            foreach ($contract['requires'] as $key) {
                if (is_string($key) && $key !== '') {
                    $requires[] = $key;
                }
            }
        }

        $promote = is_array($contract['promote'] ?? null) ? $contract['promote'] : [];
        if ($requires === [] && isset($promote['requires']) && is_array($promote['requires'])) {
            foreach ($promote['requires'] as $key) {
                if (is_string($key) && $key !== '') {
                    $requires[] = $key;
                }
            }
        }

        $minRate = $contract['min_replay_pass_rate'] ?? $promote['min_replay_pass_rate'] ?? null;
        $requireReplay = $contract['require_replay'] ?? $promote['require_replay'] ?? null;

        return new self(
            requires: array_values(array_unique($requires)),
            minReplayPassRate: is_numeric($minRate) ? (float) $minRate : null,
            requireReplay: is_bool($requireReplay) ? $requireReplay : null,
        );
    }

    public function shouldRunCheck(string $key): bool
    {
        if ($this->requires === []) {
            return true;
        }

        return in_array($key, $this->requires, true);
    }

    public function effectiveMinReplayPassRate(): float
    {
        if ($this->minReplayPassRate !== null) {
            return max(0.0, min(100.0, $this->minReplayPassRate));
        }

        return (float) config('deploy_contract.min_replay_pass_rate', 99.0);
    }

    public function effectiveRequireReplay(): bool
    {
        if ($this->requireReplay !== null) {
            return $this->requireReplay;
        }

        return (bool) config('deploy_contract.require_replay_when_enabled', true);
    }

    /**
     * @return array{requires: list<string>, min_replay_pass_rate: ?float, require_replay: ?bool}
     */
    public function toArray(): array
    {
        return [
            'requires' => $this->requires,
            'min_replay_pass_rate' => $this->minReplayPassRate,
            'require_replay' => $this->requireReplay,
        ];
    }
}
