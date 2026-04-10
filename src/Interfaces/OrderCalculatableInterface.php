<?php

namespace Livewirez\Billing\Interfaces;

use JsonSerializable;
use Livewirez\Billing\Enums\CurrencyType;

interface OrderCalculatableInterface extends JsonSerializable
{
    public function getCurrencyCode(): string;

    public function getCurrencyType(): CurrencyType;

    public function getItemTotals(bool $use_discounts = true): int;

    public function getItemTaxTotals(): int;

    public function getItemExtraTaxTotals(int $extra_tax = 0): int;

    public function getDiscountTotal(): int;

    public function getShippingDiscountTotal(): int;
    
    public function getShippingTotal(): int;

    public function getHandlingTotal(): int;

    public function getInsuranceTotal(): int;

    public function getGrandTotal(bool $use_discounts = true): int;

    public function getGrandTotalFromExtraTax(bool $use_discounts = true, int $extra_tax = 0): int;
}