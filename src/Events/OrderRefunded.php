<?php 

namespace Livewirez\Billing\Events;

use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Traits\AsLaravelEvent;

class OrderRefunded
{
    use AsLaravelEvent;
    
    public function __construct(
        public BillingOrder $order
    ) {}
}

