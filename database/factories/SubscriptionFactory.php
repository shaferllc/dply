<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Subscription;
use Laravel\Cashier\Database\Factories\SubscriptionFactory as CashierSubscriptionFactory;

class SubscriptionFactory extends CashierSubscriptionFactory
{
    protected $model = Subscription::class;
}
