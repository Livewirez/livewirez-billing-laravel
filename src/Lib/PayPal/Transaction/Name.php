<?php 

namespace Livewirez\Billing\Lib\PayPal\Transaction;

readonly class Name implements \JsonSerializable 
{
    private string $givenName;
    private string $surname;
    
    public function __construct(string $givenName, string $surname) {
        $this->givenName = $givenName;
        $this->surname = $surname;
    }
    
    public function getGivenName(): string {
        return $this->givenName;
    }
    
    public function getSurname(): string {
        return $this->surname;
    }
    
    public function getFullName(): string {
        return $this->givenName . ' ' . $this->surname;
    }

    public function jsonSerialize(): mixed
    {
        return [
            'given_name' => $this->getGivenName(),
            'surname' => $this->getSurname()
        ];
    }

}