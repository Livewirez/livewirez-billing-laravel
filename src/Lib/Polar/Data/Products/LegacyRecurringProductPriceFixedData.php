<?php

namespace Livewirez\Billing\Lib\Polar\Data\Products;



class LegacyRecurringProductPriceFixedData extends LegacyRecurringProductPriceData
{
    public function __construct(
        /**
         * Allowed value: `"fixed"`
         */
        public readonly string $amountType,
        /**
         * The currency.
         */
        public readonly string $priceCurrency,
        /**
         * The price in cents.
         */
        public readonly int $priceAmount,
    ) {}
}
