<?php 

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum SubscriptionEvent: string 
{
    use ArchTechTrait;

    case Initial = 'initial';
    case Renewal = 'renewal';
    case Upgrade = 'upgrade';
    case Downgrade = 'downgrade';
    case Cancellation = 'cancellation';
    case Pause = 'pause';

    case Resume = 'resume';
    case Expiration = 'expiration';
    case Retry = 'retry';
    case Change = 'change';
    case PriceChange = 'price_change';

    case TransactionFailure = 'transaction_failure';
    case TransactionRefunded = 'transaction_refunded';
    case TransactionCompleted = 'transaction_completed';
    case TransactionProcessing = 'transaction_processing';
}
