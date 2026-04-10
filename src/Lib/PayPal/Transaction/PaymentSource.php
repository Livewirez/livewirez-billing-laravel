<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class PaymentSource implements \JsonSerializable 
{
    private PayPal $paypal;
    
    public function __construct(PayPal $paypal) {
        $this->paypal = $paypal;
    }
    
    public function getPaypal(): PayPal {
        return $this->paypal;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'paypal' => $this->getPaypal(),
        ];
    }
}