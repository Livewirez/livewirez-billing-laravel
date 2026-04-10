<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class PurchaseUnit implements \JsonSerializable 
{
    private string $referenceId;
    private Shipping $shipping;
    private Payments $payments;
    
    public function __construct(string $referenceId, Shipping $shipping, Payments $payments) {
        $this->referenceId = $referenceId;
        $this->shipping = $shipping;
        $this->payments = $payments;
    }
    
    public function getReferenceId(): string {
        return $this->referenceId;
    }
    
    public function getShipping(): Shipping {
        return $this->shipping;
    }
    
    public function getPayments(): Payments {
        return $this->payments;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'reference_id' => $this->getReferenceId(),
            'shipping' => $this->getShipping(),
            'payments' => $this->getPayments(),
        ];
    }
}
