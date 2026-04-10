<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class Address implements \JsonSerializable 
{
    private string $countryCode;
    
    public function __construct(string $countryCode) {
        $this->countryCode = $countryCode;
    }
    
    public function getCountryCode(): string {
        return $this->countryCode;
    }

    public function jsonSerialize(): mixed {
        return [
            'country_code' => $this->getCountryCode(),
        ]; 
    }
}