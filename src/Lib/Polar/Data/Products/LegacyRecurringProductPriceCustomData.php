<?php

namespace Livewirez\Billing\Lib\Polar\Data\Products;


class LegacyRecurringProductPriceCustomData extends LegacyRecurringProductPriceData
{
    public function __construct(
        /**
         * Allowed value: `"custom"`
         */
        public readonly string $amountType,
        /**
         * The currency.
         */
        public readonly string $priceCurrency,
        /**
         * The minimum amount the customer can pay.
         */
        public readonly ?int $minimumAmount,
        /**
         * The maximum amount the customer can pay.
         */
        public readonly ?int $maximumAmount,
        /**
         * The initial amount shown to the customer.
         */
        public readonly ?int $presetAmount,
    ) {}
}
