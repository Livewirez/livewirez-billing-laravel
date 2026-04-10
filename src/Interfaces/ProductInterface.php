<?php

namespace Livewirez\Billing\Interfaces;

use JsonSerializable;
use Livewirez\Billing\Enums\ProductType;
use Livewirez\Billing\Enums\ProductCategory;


interface ProductInterface extends JsonSerializable
{
    public function getId(): int;

    public function getCurrencyCode(): string;

    public function getProductType(): ProductType;

    public function getProductCategory(): ProductCategory;

    public function getName(): string;

    public function getDescription(): ?string;

    public function getSku(): ?string;

    public function getUrl(): ?string;

    public function getImageUrl(): ?string;

    public function getUpc(): ?string;

    public function getPrice(bool $use_discounts = true): int;

    public function getListedPrice(): int;

    public function getTax(): int;

    public function getTaxWithExtraTax(int $extra_tax = 0): int;

    public function getShipping(): int;

    public function getHandling(): int;

    public function getInsurance(): int;

    public function getShippingDiscount(): int;

    public function getDiscount(): int;

    public function getPriceTotalFromExtraTax(bool $use_discounts = true, int $extra_tax = 0, int $quantity = 1): int;

    public function getPriceTotal(bool $use_discounts = true, int $quantity = 1): int;
}