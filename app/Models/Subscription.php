<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Laravel\Cashier\Database\Factories\SubscriptionFactory;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * @property string $id
 * Cashier's Subscription model, adapted to dply's ULID-keyed schema. The
 * subscriptions table stores `id` as character(26) (see pgsql-schema.sql),
 * and Cashier's default Subscription is incrementing-int — which causes
 * the eager-loaded items relation to compare ULID strings against an int
 * cast of the parent key. {@see HasUlids} fixes the key type and disables
 * incrementing in one move.
 */
class Subscription extends CashierSubscription
{
    use HasUlids;

    /**
     * Bind Cashier's factory to this subclass so tests get a ULID-generating
     * instance instead of the base Cashier model.
     */
    protected static function newFactory(): Factory
    {
        return new class extends SubscriptionFactory
        {
            protected $model = Subscription::class;
        };
    }
}
