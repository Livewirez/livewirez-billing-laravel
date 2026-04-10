<?php

namespace Livewirez\Billing\Lib\Polar\Data\Products;

use Livewirez\Billing\Lib\Polar\Data;
use Livewirez\Billing\Lib\Polar\Data\Common\PaginationData;


class ListProductsData extends Data
{
    public function __construct(
        /** @var array<ProductData> */
        public readonly array $items,
        public readonly PaginationData $pagination,
    ) {}
}
