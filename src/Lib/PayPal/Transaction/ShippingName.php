<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class ShippingName implements \JsonSerializable 
{
    private string $fullName;
    
    public function __construct(string $fullName) {
        $this->fullName = $fullName;
    }
    
    public function getFullName(): string {
        return $this->fullName;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'full_name' => $this->getFullName()
        ];
    }
}

