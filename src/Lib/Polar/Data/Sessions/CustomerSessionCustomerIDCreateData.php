<?php

namespace Livewirez\Billing\Lib\Polar\Data\Sessions;

use Livewirez\Billing\Lib\Polar\Data;
class CustomerSessionCustomerIDCreateData extends Data
{
    public function __construct(
        /**
         * ID of the customer to create a session for.
         */
        public readonly string $customerId,
    ) {}
}
