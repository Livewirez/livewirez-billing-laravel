<?php

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum DeliveryStatus: string 
{

    use ArchTechTrait;

    case FAILED = 'FAILED';
    case AWAITING_PROCESSING = 'AWAITING_PROCESSING';
    case AWAITING_RETURN = 'AWAITING_RETURN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case PICKED = 'PICKED';
    case PACKED = 'PACKED';
    case SHIPPED = 'SHIPPED';
    case DELIVERED = 'DELIVERED';
    case PARTIALLY_SHIPPED = 'PARTIALLY_SHIPPED';
    case ON_HOLD = 'ON_HOLD';
    case CANCELLED = 'CANCELLED';
    case CANCELED = 'CANCELED';
    case RETURNED = 'RETURNED';
    case REFUNDED = 'REFUNDED';
    case AWAITING_PICKUP = 'AWAITING_PICKUP';
}