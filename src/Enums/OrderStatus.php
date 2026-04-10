<?php 

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum OrderStatus: string
{
    use ArchTechTrait;

    case PENDING = 'PENDING';
    case FAILED = 'FAILED';
    case PROCESSING = 'PROCESSING';
    case PROCESSED = 'PROCESSED';
    case COMPLETED = 'COMPLETED';
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';
    case CANCELED = 'CANCELED';
    case REFUNDED = 'REFUNDED';

    case AWAITING_PROCESSING = 'AWAITING_PROCESSING';
    case AWAITING_DELIVERY = 'AWAITING_DELIVERY';
    case AWAITING_RETURN = 'AWAITING_RETURN';

    case PAYMENT_PROVIDER_UNAVAILABLE = 'PAYMENT_PROVIDER_UNAVAILABLE';
    
    case PAYMENT_PROVIDER_MISMATCH = 'PAYMENT_PROVIDER_MISMATCH';
}