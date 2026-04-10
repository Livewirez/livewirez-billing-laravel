<?php

namespace Livewirez\Billing\Lib\Polar\Events;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Traits\AsLaravelEvent;
use Livewirez\Billing\Models\BillingOrder;

class OrderPaid
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
         * The Payment entity.
         */
        public BillingOrder $billingOrder,
        /**
         * The payload array.
         */
        public array $payload,
    ) {}
}
