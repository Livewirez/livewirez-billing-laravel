<?php 

namespace Livewirez\Billing\Events;

use Livewirez\Billing\Models\BillingSubscription;
use Livewirez\Billing\Traits\AsLaravelEvent;

class SubscriptionCanceled
{
    use AsLaravelEvent;
    
    public function __construct(
        public BillingSubscription $subscription
    ) {}
}