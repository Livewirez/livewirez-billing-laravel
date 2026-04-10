<?php

namespace Livewirez\Billing\Lib\Polar\Events;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Models\BillingPaymentTransaction;
use Livewirez\Billing\Traits\AsLaravelEvent;

class PaymentUpdated
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
        /**
         * Whether the order is refunded.
         */
        public bool $isRefunded,
    ) {}
}
