<?php 

namespace Livewirez\Billing\Lib\Polar\Data\Customers;

use Livewirez\Billing\Lib\Polar\Data;

class CustomerBillingAddressData extends Data
{
    public function __construct(
        public readonly string $country = '',
        public readonly ?string $line1 = null,
        public readonly ?string $line2 = null,
        public readonly ?string $postalCode = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
    ) {}
}