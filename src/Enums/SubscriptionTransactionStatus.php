<?php 

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum SubscriptionTransactionStatus: string 
{
    use ArchTechTrait;

    case PENDING   = 'PENDING';    // Waiting for payment or gateway confirmation.
    case PROCESSING = 'PROCESSING';
    case PROCESSED = 'PROCESSED';
    case COMPLETED = 'COMPLETED';  // Transaction fully processed and subscription benefits activated.
    case FAILED    = 'FAILED';     // Payment or processing failed.
    case CANCELED  = 'CANCELED';   // Transaction was canceled by the user or system before completion.
    case EXPIRED   = 'EXPIRED';    // Transaction expired before payment or completion.
    case REFUNDED  = 'REFUNDED';  // Transaction was refunded (even if payment succeeded before).
    case RETRYING  = 'RETRYING';  // The system is attempting to reprocess (common for failed renewals).
    case ON_HOLD   = 'ON_HOLD';  // Temporarily paused, pending manual action or verification.
}