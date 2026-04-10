<?php

namespace Livewirez\Billing\Lib\Polar\Data\Products;

use Livewirez\Billing\Lib\Polar\Data;
use Livewirez\Billing\Lib\Polar\Data\Benefits\BenefitsData;


class ProductData extends Data
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
         * The ID of the product.
         */
        public readonly string $id,
        /**
         * The name of the product.
         */
        public readonly string $name,
        /**
         * The description of the product.
         */
        public readonly ?string $description,
        /**
         * The recurring interval of the product. If `None`, the product is a one-time purchase.
         *
         * Available options: `month`, `year`
         */
        public readonly ?string $recurringInterval,
        /**
         * Whether the product is a subscription.
         */
        public readonly bool $isRecurring,
        /**
         * Whether the product is archived and no longer available.
         */
        public readonly bool $isArchived,
        /**
         * The ID of the organization owning the product.
         */
        public readonly string $organizationId,
        /**
         * List of prices for this product.
         *
         * @var array<LegacyRecurringProductPriceFixedData|LegacyRecurringProductPriceCustomData|LegacyRecurringProductPriceFreeData|ProductPriceFixedData|ProductPriceCustomData|ProductPriceFreeData|array>
         */
        public readonly array $prices,
        /**
         * List of benefits granted by the product.
         *
         * @var array<BenefitsData>
         */
        public readonly array $benefits,
        /**
         * List of media associated with the product.
         *
         * @var array<ProductMediaData|array>|null
         */
        public readonly ?array $medias,

        public readonly array $metadata = [],
    ) {}
}
