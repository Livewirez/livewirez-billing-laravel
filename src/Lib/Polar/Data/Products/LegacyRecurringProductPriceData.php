<?php

namespace Livewirez\Billing\Lib\Polar\Data\Products;

use Livewirez\Billing\Lib\Polar\Data;


class LegacyRecurringProductPriceData extends Data
{
    public function __construct(
        /**
         * Creation timestamp of the object.
         */
        public readonly string $createdAt,
        /**
         * Last modification timestamp of the object.
         */
        public readonly ?string $modifiedAt,
        /**
         * The ID of the price.
         */
        public readonly string $id,
        /**
         * Whether the price is archived and no longer available.
         */
        public readonly bool $isArchived,
        /**
         * The ID of the product owning the price.
         */
        public readonly string $productId,
        /**
         * The type of the price.
         *
         * Allowed value: `"recurring"`
         */
        public readonly string $type,
        /**
         * The recurring interval of the price.
         *
         * Available options: `month`, `year`
         */
        public readonly string $recurringInterval,
        public readonly bool $legacy,
    ) {}
}
