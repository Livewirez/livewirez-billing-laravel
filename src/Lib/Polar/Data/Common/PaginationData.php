<?php 

namespace Livewirez\Billing\Lib\Polar\Data\Common;

use Livewirez\Billing\Lib\Polar\Data;

class PaginationData extends Data
{
    public function __construct(
        public readonly int $totalCount,
        public readonly int $maxPage,
    ) {}
}