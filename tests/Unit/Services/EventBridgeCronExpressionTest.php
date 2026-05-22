<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Serverless\Aws\EventBridgeCronExpression;
use InvalidArgumentException;
use Tests\TestCase;

class EventBridgeCronExpressionTest extends TestCase
{
    public function test_an_every_day_schedule_gets_a_question_mark_day_of_week(): void
    {
        // Both day fields wildcard → EventBridge needs `?` in one of them.
        $this->assertSame('cron(*/5 * * * ? *)', EventBridgeCronExpression::fromStandardCron('*/5 * * * *'));
    }

    public function test_a_day_of_month_schedule_keeps_the_day_and_blanks_day_of_week(): void
    {
        $this->assertSame('cron(0 0 1 * ? *)', EventBridgeCronExpression::fromStandardCron('0 0 1 * *'));
    }

    public function test_a_day_of_week_schedule_shifts_to_eventbridge_numbering(): void
    {
        // Standard Monday is 1; EventBridge Monday is 2 (1 = Sunday there).
        $this->assertSame('cron(0 9 ? * 2 *)', EventBridgeCronExpression::fromStandardCron('0 9 * * 1'));
    }

    public function test_standard_sunday_zero_and_seven_both_map_to_eventbridge_one(): void
    {
        $this->assertSame('cron(0 12 ? * 1 *)', EventBridgeCronExpression::fromStandardCron('0 12 * * 0'));
        $this->assertSame('cron(0 12 ? * 1 *)', EventBridgeCronExpression::fromStandardCron('0 12 * * 7'));
    }

    public function test_a_day_of_week_list_shifts_every_token(): void
    {
        // Mon,Wed,Fri (1,3,5) → 2,4,6 in EventBridge numbering.
        $this->assertSame('cron(0 8 ? * 2,4,6 *)', EventBridgeCronExpression::fromStandardCron('0 8 * * 1,3,5'));
    }

    public function test_it_rejects_specifying_both_day_of_month_and_day_of_week(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventBridgeCronExpression::fromStandardCron('0 0 1 * 1');
    }

    public function test_it_rejects_a_non_five_field_expression(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EventBridgeCronExpression::fromStandardCron('* * * *');
    }
}
