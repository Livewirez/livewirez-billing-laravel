<?php 

namespace Livewirez\Billing\Lib\Polar\Data\Checkout;

use DateTime;

use Livewirez\Billing\Lib\Polar\Data;

class CustomFieldSelectPropertiesData extends Data
{
    public function __construct(
        
        /** @var array<CustomFieldSelectOptionData> */
        public readonly array $options = [],
        /**
         * Minimum length: `1`
         */
        public readonly ?string $formLabel,
        /**
         * Minimum length: `1`
         */
        public readonly ?string $formHelpText,
        /**
         * Minimum length: `1`
         */
        public readonly ?string $formPlaceholder
    ) {}
}