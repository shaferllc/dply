<?php

declare(strict_types=1);

namespace App\Services\Servers;

use Cron\CronExpression;

final class CronExpressionValidator
{
    public function isValid(string $expression): bool
    {
        $expression = trim($expression);

        return $expression !== '' && CronExpression::isValidExpression($expression);
    }
}
