<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\PaymentResult;
use Livewirez\Billing\OrdersManager;
use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Models\BillablePaymentMethod;

class CompletePaymentWithToken
{
    public function __construct(protected OrdersManager $ordersManager) {}


    public function handle(
        Billable $payer, PaymentProvider|string $paymentProvider, Cart $cart,
        BillablePaymentMethod | string $token, array $paymentData, array $metadata = []
    ): PaymentResult
    {
        return $this->ordersManager->completePaymentWithToken(
            $payer,
            $paymentProvider,
            $cart,
            $token,
            $paymentData,
            $metadata
        );
    }
}