<?php 

namespace Livewirez\Billing\Events;

use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Traits\AsLaravelEvent;

class SubscriptionIntitated
{
    use AsLaravelEvent;
    
    public function __construct(
        public BillingSubscription $subscription
    ) {}
}