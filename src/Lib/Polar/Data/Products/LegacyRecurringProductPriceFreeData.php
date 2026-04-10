<?php

namespace Livewirez\Billing\Lib\Polar\Data\Products;



class LegacyRecurringProductPriceFreeData extends LegacyRecurringProductPriceData
{
    public function __construct(
        /**
         * Allowed value: `"free"`
         */
        public readonly string $amountType,
    ) {}
}
