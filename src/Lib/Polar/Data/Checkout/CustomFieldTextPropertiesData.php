<?php

namespace Livewirez\Billing\Lib\Polar\Data\Checkout;

use DateTime;
use Livewirez\Billing\Lib\Polar\Data;

class CustomFieldTextPropertiesData extends Data
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
        public readonly ?bool $textarea,
        /**
         * Required range: `x >= 0`
         */
        public readonly ?int $minLength,
        /**
         * Required range: `x >= 0`
         */
        public readonly ?int $maxLength,
    ) {}
}