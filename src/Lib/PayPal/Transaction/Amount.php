<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class Amount implements \JsonSerializable 
{
    private string $currencyCode;
    private string $value;
    
    public function __construct(string $currencyCode, string $value) {
        $this->currencyCode = $currencyCode;
        $this->value = $value;
    }
    
    public function getCurrencyCode(): string {
        return $this->currencyCode;
    }
    
    public function getValue(): string {
        return $this->value;
    }
    
    public function getFormatted(): string {
        return $this->currencyCode . ' ' . $this->value;
    }

    public function jsonSerialize(): mixed {
        return [
            'currency_code' => $this->getCurrencyCode(),
            'value' => $this->getValue()
        ]; 
    }
}