<?php

namespace Livewirez\Billing\Interfaces;

use JsonSerializable;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Livewirez\Billing\Lib\CartItem;

interface CartItemContainerInterface extends OrderCalculatableInterface
{
    public function add(CartItemInterface $item): static;

    public function remove(CartItemInterface $item): static;

    public function get(int $product_id): ?CartItemInterface;

    public function all(): array;

    public static function fromCartItem(CartItemInterface $cart_item): static; 

    public static function fromProduct(ProductInterface $product, int $quantity = 1): static; 

    public function getProducts(): array;

    public function getTotalItemCount(): int;

    public function addProduct(ProductInterface $product, int $quantity = 1): static;

    public function removeProduct(ProductInterface $product): static;

    public function toArray(): array;
}