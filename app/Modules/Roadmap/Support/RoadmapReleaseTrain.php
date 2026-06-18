<?php

declare(strict_types=1);

namespace App\Modules\Roadmap\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class RoadmapReleaseTrain
{
    public static function isValidSlug(?string $slug): bool
    {
        if ($slug === null || $slug === '') {
            return false;
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $slug)) {
            return false;
        }

        return checkdate((int) substr($slug, 5, 2), 1, (int) substr($slug, 0, 4));
    }

    public static function slugFromDate(CarbonImmutable $date): string
    {
        return sprintf('%04d-%02d', $date->year, $date->month);
    }

    public static function monthLabel(string $slug): string
    {
        self::assertValidSlug($slug);

        $date = CarbonImmutable::createFromFormat('Y-m-d', $slug.'-01');

        return $date->format('F Y');
    }

    public static function trainLabel(string $slug): string
    {
        self::assertValidSlug($slug);

        [$year, $month] = explode('-', $slug);

        return sprintf('Release %s.%s', $year, $month);
    }

    /**
     * @return array<string, string> slug => train label
     */
    public static function upcomingOptions(int $count = 12): array
    {
        $options = [];
        $cursor = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i < $count; $i++) {
            $slug = self::slugFromDate($cursor);
            $options[$slug] = self::trainLabel($slug).' · '.self::monthLabel($slug);
            $cursor = $cursor->addMonth();
        }

        return $options;
    }

    private static function assertValidSlug(string $slug): void
    {
        if (! self::isValidSlug($slug)) {
            throw new InvalidArgumentException('Invalid roadmap release train slug.');
        }
    }
}
