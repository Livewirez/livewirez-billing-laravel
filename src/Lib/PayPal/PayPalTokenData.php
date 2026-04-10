<?php 

namespace Livewirez\Billing\Lib\PayPal;

use Livewirez\Billing\Lib\Address;


class PayPalTokenData
{
    public function __construct(
        public string $vault_id,
        public string $token,
        public Address $address,
        public string $payer_id,
        public ?string $token_customer_id = null,
        public string $usage_type = 'MERCHANT',
        public string $customer_type = 'CONSUMER',
    ) {}
}