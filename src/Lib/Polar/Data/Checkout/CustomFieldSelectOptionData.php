<?php 

namespace Livewirez\Billing\Lib\Polar\Data\Checkout;

use Livewirez\Billing\Lib\Polar\Data;

class CustomFieldSelectOptionData extends Data
{
    public function __construct(
        /**
         * Minimum length: `1`
         */
        public readonly string $value,
        /**
         * Minimum length: `1`
         */
        public readonly string $label,
    ) {}
}