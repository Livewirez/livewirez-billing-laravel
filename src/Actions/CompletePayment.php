<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\OrdersManager;
use Livewirez\Billing\PaymentResult;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Lib\CheckoutDetails;
use Livewirez\Billing\Enums\PaymentProvider;

class CompletePayment
{
    public function __construct(protected OrdersManager $ordersManager) {}


    public function handle(
        Billable $payer, PaymentProvider|string $provider,
        CheckoutDetails $checkoutDetails, string $providerOrderId, array $metadata = []
    ): ?PaymentResult
    {
        return $this->ordersManager->completePayment(
            $payer,
            $provider,
            $checkoutDetails,
            $providerOrderId,
            $metadata
        );
    }
}