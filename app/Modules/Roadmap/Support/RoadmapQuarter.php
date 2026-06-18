<?php

declare(strict_types=1);

namespace App\Modules\Roadmap\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class RoadmapQuarter
{
    /**
     * @return array<string, string> key => label (e.g. 2026-Q3 => Q3 2026)
     */
    public static function options(int $count = 8): array
    {
        $options = [];
        $cursor = CarbonImmutable::now()->startOfQuarter();

        for ($i = 0; $i < $count; $i++) {
            $key = self::keyForDate($cursor);
            $options[$key] = self::labelForKey($key);
            $cursor = $cursor->addQuarter();
        }

        return $options;
    }

    public static function keyForDate(CarbonImmutable $date): string
    {
        return sprintf('%d-Q%d', $date->year, $date->quarter);
    }

    public static function labelForKey(string $key): string
    {
        if (! self::isValidKey($key)) {
            throw new InvalidArgumentException('Invalid roadmap quarter key.');
        }

        [$year, $quarter] = explode('-Q', $key);

        return sprintf('Q%s %s', $quarter, $year);
    }

    public static function isValidKey(?string $key): bool
    {
        if ($key === null || $key === '') {
            return false;
        }

        return (bool) preg_match('/^\d{4}-Q[1-4]$/', $key);
    }
}
