<?php

namespace Livewirez\Billing\Traits;

trait ProductPricingTrait
{
    public function getPrice(bool $use_discounts = true): int
    {
       return $use_discounts ? ($this->getListedPrice() - $this->discount - $this->shipping_discount)
        : $this->getListedPrice();
    }

    public function getListedPrice(): int
    {
        return $this->price;
    }


    public function getTax(): int
    {
        // return $this->tax;
        return match($this->tax_type) {
            'percent' => $this->purchase_price * ($this->tax / 100),
            'numeric' => $this->tax,
            default => $this->tax
        };
    }

    public function getTaxWithExtraTax(int $extra_tax = 0): int
    {
        return $this->getTax() + $extra_tax;
    }

    public function getShipping(): int
    {
        return $this->shipping;
    }

    public function getHandling(): int
    {
        return $this->handling;
    }

    public function getInsurance(): int
    {
        return $this->insurance;
    }

    public function getShippingDiscount(): int
    {
        return $this->shipping_discount;
    }

    public function getDiscount(): int
    {
        return $this->discount;
    }

    public function getPriceTotalFromExtraTax(bool $use_discounts = true, int $extra_tax = 0, int $quantity = 1): int
    {
        return ($this->getPrice($use_discounts) * $quantity) 
        + ($this->getTaxWithExtraTax($extra_tax) * $quantity)
        + ($this->getShipping() * $quantity)
        + ($this->getHandling() * $quantity)
        + ($this->getInsurance() * $quantity);
    }

    public function getPriceTotal(bool $use_discounts = true, int $quantity = 1): int
    {
        return ($this->getPrice($use_discounts) * $quantity)
        + ($this->getTax() * $quantity)
        + ($this->getShipping() * $quantity)
        + ($this->getHandling() * $quantity)
        + ($this->getInsurance() * $quantity);
    }
}