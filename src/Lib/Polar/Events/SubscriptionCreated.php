<?php

namespace Livewirez\Billing\Lib\Polar\Events;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Traits\AsLaravelEvent;

class SubscriptionCreated
{
    use AsLaravelEvent;

    /**
     * Create a new event instance.
     */
    public function __construct(
        /**
         * The billable entity.
         */
        public Billable $billable,
        /**
         * The order entity.
         */
        public BillingSubscription $subscription,
        /**
         * The payload array.
         */
        public array $payload,
    ) {}
}
