<?php 

namespace Livewirez\Billing\Actions;

use Livewirez\Billing\Interfaces\Billable;
use Livewirez\Billing\Lib\Cart;
use Livewirez\Billing\PaymentResult;
use Livewirez\Billing\OrdersManager;
use Livewirez\Billing\Enums\PaymentProvider;
use Livewirez\Billing\Interfaces\ProductInterface;
use Livewirez\Billing\Lib\Orders\InitializeOrderRequest;

class ResumeOrder
{
    public function __construct(protected OrdersManager $ordersManager) {}


    public function handle(
        Billable $payer, PaymentProvider|string $provider,
        Cart | ProductInterface $cart, InitializeOrderRequest $request,
        array $metadata = []
    ): PaymentResult 
    {
        $cart = $cart instanceof ProductInterface ? Cart::fromProduct($cart) : $cart;

        return $this->ordersManager->resumeOrder(
            $payer,
            $provider,
            $cart,
            $request,
            $metadata
        );
    }
}