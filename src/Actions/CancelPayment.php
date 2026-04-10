<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\PaymentResult;
use Livewirez\Billing\OrdersManager;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Enums\PaymentProvider;

class CancelPayment
{
    public function __construct(protected OrdersManager $ordersManager) {}


    public function handle(
        Billable $payer, PaymentProvider|string $provider,
        string $providerOrderId,
        array $metadata = []
    ): bool
    {
        return $this->ordersManager->cancelPayment(
            $payer,
            $provider,
            $providerOrderId,
            $metadata
        );
    }
}