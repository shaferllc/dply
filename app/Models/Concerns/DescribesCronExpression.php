<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Cron\CronDescriber;

/**
 * Gives any model with a `cron_expression` column a human-readable description
 * of its schedule ("Every day at 3:00am"). Null when the expression is empty or
 * can't be translated, so views can fall back to the raw expression.
 *
 * @property string|null $cron_expression
 */
trait DescribesCronExpression
{
    public function cronDescription(): ?string
    {
        return CronDescriber::describe($this->cron_expression);
    }
}
