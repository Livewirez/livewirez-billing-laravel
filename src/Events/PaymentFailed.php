<?php 

namespace Livewirez\Billing\Events;

use Livewirez\Billing\Models\BillingOrder;
use Livewirez\Billing\Traits\AsLaravelEvent;
use Livewirez\Billing\Models\BillingPaymentTransaction;

class PaymentFailed
{
    use AsLaravelEvent;
    
    public function __construct(
        public BillingOrder $order,
        public BillingPaymentTransaction $paymentTransaction
    ) {}
}

