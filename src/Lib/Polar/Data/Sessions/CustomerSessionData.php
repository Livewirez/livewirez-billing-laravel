<?php

namespace Livewirez\Billing\Lib\Polar\Data\Sessions;

use Livewirez\Billing\Lib\Polar\Data;
use Livewirez\Billing\Lib\Polar\Data\Customers\CustomerData;

class CustomerSessionData extends Data
{
    public function __construct(
        /**
         * The ID of the customer session.
         */
        public readonly string $id,
        public readonly string $token,
        public readonly string $expiresAt,
        public readonly string $customerPortalUrl,
        public readonly string $customerId,
        public readonly CustomerData $customer,
        /**
         * Creation timestamp of the object.
         */
        public readonly string $createdAt,
        /**
         * Last modification timestamp of the object.
         */
        public readonly ?string $modifiedAt,
    ) {}
}
