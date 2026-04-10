<?php 

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum SubscriptionStatus: string 
{
    use ArchTechTrait;
    
    case DEFAULT = 'DEFAULT';

    case EXISTS = 'EXISTS';

    case CANCELLATION_PENDING = 'CANCELLATION_PENDING';

    case APPROVAL_PENDING	= 'APPROVAL_PENDING'; // The subscription is created but not yet approved by the buyer

    case APPROVED = 'APPROVED'; // The buyer has approved the subscription.	

    case ACTIVE = 'ACTIVE'; // The subscription is active.

    case SUSPENDED = 'SUSPENDED'; // The subscription is suspended.

    case CANCELED = 'CANCELED';

    case CANCELLED = 'CANCELLED'; // The subscription is cancelled.

    case EXPIRED = 'EXPIRED'; // The subscription is expired.

    case PAST_DUE = 'PAST_DUE';

    case PENDING = 'PENDING';

    case PAUSED = 'PAUSED';

    case RENEWED = 'RENEWED';
    
    case INACTIVE = 'INACTIVE';

    case FAILED = 'FAILED';

    case TRIALING = 'TRIALING';

    case TRIAL_ENDED = 'TRIAL_ENDED';

    case ENDS_IN_THE_FUTURE = 'ENDS_IN_THE_FUTURE';

    case PAYMENT_PROVIDER_UNAVAILABLE = 'PAYMENT_PROVIDER_UNAVAILABLE';

    case PAYMENT_PROVIDER_MISMATCH = 'PAYMENT_PROVIDER_MISMATCH';
}