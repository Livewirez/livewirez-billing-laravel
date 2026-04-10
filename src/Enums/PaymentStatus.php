<?php

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum PaymentStatus: string
{
    use ArchTechTrait;


    case DEFAULT = 'DEFAULT';

    case PENDING = 'PENDING';

    case PROCESSING = 'PROCESING';

    case PAID = 'PAID';

    case APPROVED = 'APPROVED';

    case UNPAID = 'UNPAID';

    case COMPLETED = 'COMPLETED';

    case FAILED = 'FAILED';

    case CANCELED = 'CANCELED';

    case CANCELLED = 'CANCELLED';

    case REFUNDED = 'REFUNDED';

    case PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';

    case EXPIRED = 'EXPIRED';

    case NOT_FOUND = 'NOT_FOUND';

    case PAST_DUE = 'PAST_DUE';

    case CHARGEBACK = 'CHARGEBACK';

    case PENDING_VAULT = 'PENDING_VAULT';

    case PAYMENT_PROVIDER_UNAVAILABLE = 'PAYMENT_PROVIDER_UNAVAILABLE';
    
    case PAYMENT_PROVIDER_MISMATCH = 'PAYMENT_PROVIDER_MISMATCH';
}