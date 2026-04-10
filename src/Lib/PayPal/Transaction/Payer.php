<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class Payer implements \JsonSerializable  
{
    private Name $name;
    private string $emailAddress;
    private string $payerId;
    private Address $address;
    
    public function __construct(Name $name, string $emailAddress, string $payerId, Address $address) {
        $this->name = $name;
        $this->emailAddress = $emailAddress;
        $this->payerId = $payerId;
        $this->address = $address;
    }
    
    public function getName(): Name {
        return $this->name;
    }
    
    public function getEmailAddress(): string {
        return $this->emailAddress;
    }
    
    public function getPayerId(): string {
        return $this->payerId;
    }
    
    public function getAddress(): Address {
        return $this->address;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'name' => $this->getName(),
            'email_address' => $this->getEmailAddress(),
            'payer_id' => $this->getPayerId(),
            'address' => $this->getAddress()
        ];
    }
}
