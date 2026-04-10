<?php

namespace Livewirez\Billing\Interfaces;

use Livewirez\Billing\Interfaces\ProductInterface;

interface CartItemInterface
{
    public function getProduct(): ProductInterface;

    public function setProduct(ProductInterface $product): static;

    public function setQuantity(int $quantity): static;

    public function getQuantity(): int;
}