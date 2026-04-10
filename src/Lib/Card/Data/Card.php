<?php

namespace Livewirez\Billing\Lib\Card\Data;

use Livewirez\Billing\Lib\Address;
use Livewirez\Billing\Lib\Card\Enums\CardType;

class Card 
{
    public function __construct(
        public string $holderFirstName,
        public string $holderLastName,
        public string $number,        // PAN (Primary Account Number)
        public string $expiryMonth,   // MM
        public string $expiryYear,    // YYYY
        public string $cvc,           // Card Verification Code
        public string $brand,         // Visa, MasterCard, etc.
        public CardType $type = CardType::Unknown,       // Credit, Debit, Prepaid
        public ?Address $customerBillingAddress = null,
        public ?Address $customerShippingAddress = null,
    ) {}

    public function maskedNumber(): string 
    {
        return str_repeat('*', strlen($this->number) - 4) . substr($this->number, -4);
    }

    public function isExpired(): bool 
    {
        $exp = \DateTime::createFromFormat('m-Y', $this->expiryMonth . '-' . $this->expiryYear);
        return $exp < new \DateTime('first day of this month');
    }
}