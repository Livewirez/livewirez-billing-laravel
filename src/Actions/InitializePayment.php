<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\PaymentResult;
use Livewirez\Billing\OrdersManager;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Interfaces\ProductInterface;

class InitializePayment
{
    public function __construct(protected OrdersManager $ordersManager) {}


    public function handle(
        Billable $payer, PaymentProvider|string $provider,
        Cart | ProductInterface $cart, array $paymentData = [],
        array $metadata = []
    ): PaymentResult 
    {
        $cart = $cart instanceof ProductInterface ? Cart::fromProduct($cart) : $cart;

        return $this->ordersManager->initializePayment(
            $payer,
            $provider,
            $cart,
            $paymentData,
            $metadata
        );
    }
}