<?php

namespace Livewirez\Billing;


class ConvertedCurrency
{
    public function __construct(
        public float | int $amount,
        public string $currency,
        public float | int $exchangeRate
    ) {} 

    public function formattedAmount(): float
    {
        $scale = match (strtoupper($this->currency)) {
            'BTC' => 8,
            'ETH' => 18,
            'USDT' => 6,
            'USD', 'EUR', 'KES', 'KSH' => 2,
            default => 2,
        };

        return is_int($this->amount) ? Money::formatAmount($this->amount, $scale) 
        : Money::formatMajorAmount($this->amount, $scale);
    }
}