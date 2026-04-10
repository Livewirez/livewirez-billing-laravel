<?php

namespace Livewirez\Billing\Lib\Polar\Events;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Traits\AsLaravelEvent;
use Livewirez\Billing\Models\BillingSubscription;

class SubscriptionRevoked
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
         * The subscription instance.
         */
        public BillingSubscription $subscription,
        /**
         * The payload array.
         *
         * @var array<string, mixed>
         */
        public array $payload,
    ) {}
}
