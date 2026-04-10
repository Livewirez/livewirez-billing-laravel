<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class Shipping implements \JsonSerializable 
{
    private ShippingName $name;
    private ShippingAddress $address;
    
    public function __construct(ShippingName $name, ShippingAddress $address) {
        $this->name = $name;
        $this->address = $address;
    }
    
    public function getName(): ShippingName {
        return $this->name;
    }
    
    public function getAddress(): ShippingAddress {
        return $this->address;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'name' => $this->getName(),
            'address' => $this->getAddress(),
        ];
    }
}
