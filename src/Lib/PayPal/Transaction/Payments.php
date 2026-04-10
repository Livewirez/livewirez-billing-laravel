<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class Payments implements \JsonSerializable 
{
    private array $captures;
    
    public function __construct(array $captures) {
        $this->captures = $captures;
    }
    
    public function getCaptures(): array {
        return $this->captures;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'captures' => $this->getCaptures(),
        ];
    }
}

