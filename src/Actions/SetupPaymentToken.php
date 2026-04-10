<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\OrdersManager;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Providers\PayPalTokenProvider;

class SetupPaymentToken
{
    public function __construct(protected OrdersManager $ordersManager) {}

    public function handle(
        Billable $payer, PaymentProvider|string $paymentProvider,
        array $metadata = []
    ): array
    {
        return $this->ordersManager->setupPaymentToken($payer, $paymentProvider, $metadata);
    }
}
