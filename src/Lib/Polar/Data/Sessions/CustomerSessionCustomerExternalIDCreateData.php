<?php

namespace Livewirez\Billing\Lib\Polar\Data\Sessions;

use Livewirez\Billing\Lib\Polar\Data;

class CustomerSessionCustomerExternalIDCreateData extends Data
{
    public function __construct(
        /**
         * External ID of the customer to create a session for.
         */
        public readonly string $customerExternalId,
    ) {}
}
