<?php 

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum FulfillmentStatus: string
{
    use ArchTechTrait;

    case UNFULFILLED = 'UNFULFILLED';

    case IN_PROGRESS = 'IN_PROGRESS';
    case PARTIAL = 'PARTIAL';
    case FULFILLED = 'FULFILLED';
    case PENDING = 'PENDING';

    case AWAITING_PROCESSING = 'AWAITING_PROCESSING';

}