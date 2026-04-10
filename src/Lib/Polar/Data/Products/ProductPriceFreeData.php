<?php

namespace Livewirez\Billing\Lib\Polar\Data\Products;



class ProductPriceFreeData extends ProductPriceData
{
    public function __construct(
        /**
         * Allowed value: `"free"`
         */
        public readonly string $amountType,
    ) {}
}
