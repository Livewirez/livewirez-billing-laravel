<?php 

namespace Livewirez\Billing\Lib\Polar\Data\Checkout;

use Livewirez\Billing\Lib\Polar\Data;


class CustomFieldCheckboxPropertiesData extends Data
{
    public function __construct(
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
        public readonly ?string $formPlaceholder,
    ) {}
}