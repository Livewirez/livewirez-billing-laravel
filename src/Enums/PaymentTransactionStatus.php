<?php

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum PaymentTransactionStatus: string
{
    use ArchTechTrait;

    case DEFAULT = 'DEFAULT';

    case EXPIRED = 'EXPIRED';
    
    case PENDING = 'PENDING';

    case PAID = 'PAID';

    case APPROVED = 'APPROVED';

    case COMPLETED = 'COMPLETED';

    case FAILED = 'FAILED';

    case REFUNDED = 'REFUNDED';

    case CANCELED = 'CANCELED';

    case CANCELLED = 'CANCELLED';

    case PAYMENT_PROVIDER_UNAVAILABLE = 'PAYMENT_PROVIDER_UNAVAILABLE';
}