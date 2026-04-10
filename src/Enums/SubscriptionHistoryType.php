<?php 

namespace Livewirez\Billing\Enums;

use Livewirez\Billing\Traits\ArchTechTrait;

enum SubscriptionHistoryType: string 
{
    use ArchTechTrait;
    

   
    /** A new billing cycle started (auto-renewal or manual renewal). */
    case RENEWAL = 'RENEWAL';

    /** The subscription was explicitly canceled by the user or system. */
    case CANCELLATION = 'CANCELLATION';

    case UPDATE = 'UPDATE';

    /** The subscription naturally expired at the end of its billing period. */
    case EXPIRATION = 'EXPIRATION';

    /** The subscription was temporarily paused. */
    case PAUSE = 'PAUSE';

    /** A previously paused subscription was resumed. */
    case RESUME = 'RESUME';

    /** A subscription was first created/activated. */
    case CREATION = 'CREATION';
}