<?php

namespace Livewirez\Billing\Lib;

use Exception;
use DomainException;
use JsonSerializable;
use Livewirez\Billing\Interfaces\CartItemInterface;
use Livewirez\Billing\Enums\CurrencyType;
use Livewirez\Billing\Enums\ProductCategory;
use Livewirez\Billing\Interfaces\CartInterface;
use Livewirez\Billing\Interfaces\ProductInterface;


class Cart implements CartInterface, JsonSerializable
{
    /**
     * Pass an array of shopping cart items
     * 
     * @param CartItem[] $items
     */
    public function __construct(public array $items = [], public string $currency_code = 'USD')
    {
        $this->currency_code ??= config('billing.default_currency', 'USD');

        if (array_any(
            $this->items, 
            fn (CartItem $item) => $item->getProduct()->getCurrencyCode() !== $this->currency_code
        )) {
            throw new Exception(
                "The currency for yout products does not match the currency for your cart: {$this->currency_code}"
            );
        } 
    }

    public function getCurrencyCode(): string
    {
        return $this->currency_code;
    }

    public function setCurrencyCode(string $currency_code): static
    {
       $this->currency_code = $currency_code;

       return $this;
    }

    public function getCurrencyType(): CurrencyType
    {
        if (CurrencyCode::from($this->getCurrencyCode())->isFiat()) 
            return CurrencyType::FIAT;

        if (CurrencyCode::from($this->getCurrencyCode())->isCrypto()) 
            return CurrencyType::CRYPTO;

        throw new DomainException('Unsupported Currency Type');
    }

    public static function fromCartItem(CartItemInterface $cart_item): static 
    {
        return new static([$cart_item]);
    }

    public static function fromProduct(ProductInterface $product, int $quantity = 1): static 
    {
        $cart_item = new CartItem($product, $quantity);

        return new static([$cart_item], $product->getCurrencyCode());
    }

    public function getProducts(): array
    {
        return array_map(fn (CartItemInterface $cartItem) => $cartItem->getProduct(), $this->items);
    }

    public function getTotalItemCount(): int
    {
        return array_reduce(
            array_map(fn (CartItemInterface $item) => $item->getQuantity(), $this->items), 
            fn (int $sum, int $quantity) => $sum + $quantity, 
            0
        );
    }

    public function add(CartItemInterface $item): static 
    {
        if ($cartItem = array_find(
            $this->items, 
            fn (CartItemInterface $it, int $key) => $it->getProduct()->getId() === $item->getProduct()->getId()
        )) {
            $this->incrementQuantity($cartItem->getProduct()->getId(), $item->getQuantity());
        } else {
            array_push($this->items, $item);
        }

        return $this;
    }

    public function remove(CartItemInterface $item): static 
    {
        $this->items = array_filter(
            $this->items, 
            fn (CartItemInterface $it) => $it->getProduct()->getId() !== $item->getProduct()->getId()
        );

        return $this;
    }

    public function addProduct(ProductInterface $product, int $quantity = 1): static 
    {
        return $this->add(new CartItem($product, $quantity));
    }

    public function removeProduct(ProductInterface $product): static 
    {
        return $this->remove(new CartItem($product));
    }

    public function updateQuantity(int $product_id, int $quantity): static 
    {
        foreach ($this->items as $item) {
            if ($item->getProduct()->getId() === $product_id) {
                $item->setQuantity(max(1, $quantity)); // Ensure quantity is at least 1
                break;
            }
        }

        return $this;
    }

    public function incrementQuantity(int $product_id, int $quantity = 1): static
    {
        foreach ($this->items as $item) {
            if ($item->getProduct()->getId() === $product_id) {
                $quantity = $item->getQuantity();
                $quantity += max(1, $quantity);
                $item->setQuantity($quantity); // Ensure quantity is at least 1
                break;
            }
        }

        return $this;
    }

    public function decrementQuantity(int $product_id, int $quantity = 1): static 
    {
        foreach ($this->items as $item) {
            if ($item->getProduct()->getId() === $product_id) {
                $item->setQuantity(max(1,  $item->getQuantity() - $quantity)); // Ensure quantity is at least 1
                break;
            }
        }

        return $this;
    }

    public function clear()
    {
        $this->items = [];

        return $this;
    }

    public function replaceItems(array $items): static 
    {
        $this->items = $items;

        return $this;
    }

    public function get(int $product_id): ?CartItem
    {
        return array_find(
            $this->items,
            fn (CartItem $item, int $key) => $item->getProduct()->getId() === $product_id
        );
    }

    /**
     * Fill the fluent instance with an array of attributes.
     *
     * @param  iterable<int, CartItem>  $attributes
     * @return $this
     */
    public function fill(iterable $attributes): static 
    {
        foreach ($attributes as $key => $value) {
            $this->items[$key] = $value;
        }

        return $this;
    }

    public function removeUsingProductId(int $product_id): static 
    {
        $this->items = array_filter(
            $this->items, 
            fn (CartItem $item) => $item->getProduct()->getId() !== $product_id
        );

        return $this;
    }


    /**
     * Get all of the attributes from the fluent instance.
     *
     * @param  array|mixed|null  $keys
     * @return array
     */
    public function all(): array
    {
        return $this->items;
    }

    public function getAttributes(): array
    {
        return $this->items;
    }


    public function toArray(): array
    {
        return $this->items;
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the fluent instance to JSON.
     *
     * @param  int  $options
     * @return string
     */
    public function toJson($options = 0): bool|string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    public function getItemTotals(bool $use_discounts = true): int
    {
        return array_reduce(
            $this->items, 
            fn (int $sum, CartItem $item) => 
            $use_discounts ? $sum + (($item->getProduct()->getPrice(false) - $item->getProduct()->getDiscount() - $item->getProduct()->getShippingDiscount()) * $item->getQuantity())
            : $sum + (($item->getProduct()->getPrice(false)) * $item->getQuantity()), 
            0
        );
    }

    
    public function getItemTaxTotals(): int
    {
        return array_reduce(
            $this->items, 
            fn (int $sum, CartItem $item) => $sum + $item->getProduct()->getTax() * $item->getQuantity(), 
            0
        );
    }

    public function getItemExtraTaxTotals(int $extra_tax = 0): int
    {
        return array_reduce(
            $this->items, 
            fn (int $sum, CartItem $item) => $sum + $item->getProduct()->getTax() + $extra_tax * $item->getQuantity(), 
            0
        );
    }

    public function getDiscountTotal(): int
    {
        return array_reduce(
            $this->items,
            fn (int $sum, CartItem $item) => $sum + $item->getProduct()->getDiscount() * $item->getQuantity(),
            0
        );
    }

    public function getShippingTotal(): int
    {
        return array_reduce(
            $this->items,
            fn (int $sum, CartItem $item) => $sum + $item->getProduct()->getShipping() * $item->getQuantity(),
            0
        );
    }

    public function getShippingDiscountTotal(): int
    {
        return array_reduce(
            $this->items,
            fn (int $sum, CartItem $item) => $sum + $item->getProduct()->getShippingDiscount() * $item->getQuantity(),
            0
        );
    }

    public function getHandlingTotal(): int
    {
        return array_reduce(
            $this->items,
            fn (int $sum, CartItem $item) => $sum + $item->getProduct()->getHandling() * $item->getQuantity(),
            0
        );
    }

    public function getInsuranceTotal(): int
    {
        return array_reduce(
            $this->items,
            fn (int $sum, CartItem $item) => $sum + $item->getProduct()->getInsurance() * $item->getQuantity(),
            0
        );
    }

    public function getGrandTotal(bool $use_discounts = true): int
    {
        return $this->getItemTotals($use_discounts) 
        + $this->getItemTaxTotals() 
        + $this->getShippingTotal()
        + $this->getHandlingTotal()
        + $this->getInsuranceTotal();
    }

    public function getGrandTotalFromExtraTax(bool $use_discounts = true, int $extra_tax = 0): int
    {
        return $this->getItemTotals($use_discounts) 
        + $this->getItemExtraTaxTotals($extra_tax) 
        + $this->getShippingTotal()
        + $this->getHandlingTotal()
        + $this->getInsuranceTotal();
    }
}