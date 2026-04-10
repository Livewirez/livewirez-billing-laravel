<?php

namespace Livewirez\Billing\Lib\Polar\Events;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Traits\AsLaravelEvent;
use Livewirez\Billing\Models\BillingPaymentTransaction;

class PaymentCompleted
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
        public BillingPaymentTransaction $billingPaymentTransaction,
        /**
         * The payload array.
         */
        public array $payload,
    ) {}
}
